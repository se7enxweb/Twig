<?php

namespace Twig\Tests\Extension;

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Markup;
use Twig\Sandbox\SecurityNotAllowedFilterError;
use Twig\Sandbox\SecurityNotAllowedFunctionError;
use Twig\Sandbox\SecurityNotAllowedMethodError;
use Twig\Sandbox\SecurityPolicy;
use Twig\TwigFunction;
use Twig\TwigTest;

/**
 * Regression tests of the sandbox fixes backported from Twig 3.31.0 and 3.31.1
 * (CVE-2026-133764, CVE-2026-133767, CVE-2026-133768, CVE-2026-133770).
 */
class SandboxAdvisories202611Test extends TestCase
{
    private function env(array $templates, array $tags = [], array $filters = [], array $methods = [], array $functions = [])
    {
        $twig = new Environment(new ArrayLoader($templates), ['debug' => true, 'cache' => false, 'autoescape' => false]);
        $twig->addExtension(new SandboxExtension(new SecurityPolicy($tags, $filters, $methods, [], $functions), true));

        return $twig;
    }

    /**
     * CVE-2026-133764: the input of a custom test reaches host code that may coerce it to string.
     */
    public function testToStringOnTheInputOfACustomTestIsChecked()
    {
        $twig = $this->env(['index' => '{{ obj is unsafe_test ? "yes" : "no" }}']);
        $twig->addTest(new TwigTest('unsafe_test', function ($value) { return 'foo' === (string) $value; }));

        try {
            $twig->load('index')->render(['obj' => new Advisories202611Stringable()]);
            $this->fail('The sandbox must check __toString() on the input of a custom test');
        } catch (SecurityNotAllowedMethodError $e) {
            $this->assertSame(Advisories202611Stringable::class, $e->getClassName());
            $this->assertSame('__tostring', $e->getMethodName());
        }
    }

    public function testToStringOnTheInputOfACustomTestIsAllowedByThePolicy()
    {
        $twig = $this->env(['index' => '{{ obj is unsafe_test ? "yes" : "no" }}'], [], [], [Advisories202611Stringable::class => ['__toString']]);
        $twig->addTest(new TwigTest('unsafe_test', function ($value) { return 'foo' === (string) $value; }));

        $this->assertSame('yes', $twig->load('index')->render(['obj' => new Advisories202611Stringable()]));
    }

    public function testBuiltInTestsThatNeverCoerceTheirInputAreNotWrapped()
    {
        $twig = $this->env(['index' => '{{ obj is iterable ? 1 : 0 }}']);

        $this->assertSame('0', $twig->load('index')->render(['obj' => new Advisories202611Stringable()]));
    }

    /**
     * CVE-2026-133770: the constructor of a Markup instance rewrites its content.
     */
    public function testTheMarkupConstructorCannotBeCalled()
    {
        $markup = new Markup('<b>safe</b>', 'UTF-8');
        foreach (['{{ m.__construct("<script>", "UTF-8") }}', '{{ m.__CONSTRUCT("<script>", "UTF-8") }}'] as $template) {
            $twig = $this->env(['index' => $template]);
            try {
                $twig->load('index')->render(['m' => $markup]);
                $this->fail('The sandbox must not allow calling the Markup constructor');
            } catch (SecurityNotAllowedMethodError $e) {
                $this->assertSame(Markup::class, $e->getClassName());
                $this->assertSame('__construct', $e->getMethodName());
            }
        }

        $this->assertSame('<b>safe</b>', (string) $markup);
    }

    public function testOtherMarkupMethodsCanStillBeCalled()
    {
        $twig = $this->env(['index' => '{{ m.count }}-{{ m.count() }}']);

        $this->assertSame('3-3', $twig->load('index')->render(['m' => new Markup('<b>', 'UTF-8')]));
    }

    /**
     * CVE-2026-133768: join must not cast a non-iterable object to an array of its properties.
     */
    public function testJoinDoesNotExposeThePropertiesOfANonIterableObject()
    {
        $twig = $this->env(['index' => '{{ obj|join(",") }}'], [], ['join']);

        try {
            $out = $twig->load('index')->render(['obj' => new Advisories202611Secret()]);
            $this->fail('join must not expose the properties of a non-iterable object, got: '.$out);
        } catch (\Throwable $e) {
            // Twig 2 lets the PHP \Error through, Twig 3 wraps it in a RuntimeError
            $this->assertNotFalse(strpos($e->getMessage(), 'could not be converted to string'), $e->getMessage());
        }
    }

    public function testJoinOfAStringableObjectIsGovernedByThePolicy()
    {
        $context = ['obj' => new Advisories202611Stringable()];
        $allowing = $this->env(['index' => '{{ obj|join(",") }}'], [], ['join'], [Advisories202611Stringable::class => ['__toString']]);
        $this->assertSame('foo', $allowing->load('index')->render($context));

        $denying = $this->env(['index' => '{{ obj|join(",") }}'], [], ['join']);
        $this->expectException(SecurityNotAllowedMethodError::class);
        $denying->load('index')->render($context);
    }

    /**
     * CVE-2026-133767: the parent expression of an embed tag is checked like any other expression.
     */
    public function testToStringOnADynamicEmbedParentIsChecked()
    {
        $twig = $this->env(['index' => '{% embed obj ~ ".twig" %}{% endembed %}', 'foo.twig' => 'LEAK'], ['embed', 'extends']);

        try {
            $twig->load('index')->render(['obj' => new Advisories202611Stringable()]);
            $this->fail('The sandbox must check __toString() on a dynamic embed parent');
        } catch (SecurityNotAllowedMethodError $e) {
            $this->assertSame(Advisories202611Stringable::class, $e->getClassName());
            $this->assertSame('__tostring', $e->getMethodName());
        }
    }

    public function testFiltersInADynamicEmbedParentAreChecked()
    {
        $twig = $this->env(['index' => '{% embed "LEAK"|lower %}{% endembed %}', 'leak' => 'LEAK'], ['embed', 'extends']);

        $this->expectException(SecurityNotAllowedFilterError::class);
        $twig->load('index')->render([]);
    }

    public function testFunctionsInADynamicEmbedParentAreChecked()
    {
        foreach (['{% embed leak() %}{% endembed %}', '{% embed "" ~ leak() %}{% endembed %}'] as $template) {
            $twig = $this->env(['index' => $template, 'leak' => 'LEAK'], ['embed', 'extends']);
            $twig->addFunction(new TwigFunction('leak', function () { return 'leak'; }));
            try {
                $twig->load('index')->render([]);
                $this->fail('The sandbox must check the functions of a dynamic embed parent: '.$template);
            } catch (SecurityNotAllowedFunctionError $e) {
                $this->assertSame('leak', $e->getFunctionName());
            }
        }
    }

    public function testAnAllowedDynamicEmbedParentStillRenders()
    {
        $twig = $this->env(['index' => '{% embed name %}{% block b %}child{% endblock %}{% endembed %}', 'base.twig' => 'base {% block b %}{% endblock %}'], ['embed', 'extends', 'block']);

        $this->assertSame('base child', $twig->load('index')->render(['name' => 'base.twig']));
    }
}

class Advisories202611Stringable
{
    public function __toString()
    {
        return 'foo';
    }
}

class Advisories202611Secret
{
    private $secret = 'SECRET_PROPERTY';
    public $visible = 'VISIBLE_PROPERTY';
}
