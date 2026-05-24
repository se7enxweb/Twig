<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\Tests\Extension;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Sandbox\SecurityNotAllowedFilterError;
use Twig\Sandbox\SecurityNotAllowedFunctionError;
use Twig\Sandbox\SecurityNotAllowedTagError;
use Twig\Sandbox\SecurityPolicy;
use Twig\Sandbox\SecurityPolicyInterface;
use Twig\Sandbox\SourcePolicyInterface;
use Twig\Source;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Regression tests for the sandbox filter/tag/function allow-list bypass that
 * existed when the sandbox state changed between renders of a cached Template
 * instance (CVE-2026-46636).
 *
 * Without the fix, the compiled checkSecurity() method only ran once at
 * construction time, locking in the verdict computed against whatever sandbox
 * state was active when the template was first loaded.
 */
class SandboxStateChangeTest extends TestCase
{
    public function testEnableSandboxAfterFirstRender()
    {
        list($twig, $sandbox) = $this->build(['t' => '{{ "foo"|upper }}'], new SecurityPolicy(), false);

        $this->assertSame('FOO', $twig->render('t'));

        $sandbox->enableSandbox();

        $this->expectException(SecurityNotAllowedFilterError::class);
        $this->expectExceptionMessage('Filter "upper" is not allowed');
        $twig->render('t');
    }

    public function testDisableSandboxAfterFirstRender()
    {
        list($twig, $sandbox) = $this->build(['t' => '{{ "foo"|upper }}'], new SecurityPolicy(), false);
        $sandbox->enableSandbox();

        try {
            $twig->render('t');
            $this->fail('Expected SecurityNotAllowedFilterError on first render');
        } catch (SecurityNotAllowedFilterError $e) {
            $this->assertSame('upper', $e->getFilterName());
        }

        $sandbox->disableSandbox();

        $this->assertSame('FOO', $twig->render('t'));
    }

    public function testSetSecurityPolicyTightening()
    {
        list($twig, $sandbox) = $this->build(['t' => '{{ "foo"|upper }}'], new SecurityPolicy([], ['upper', 'escape']), true);

        $this->assertSame('FOO', $twig->render('t'));

        $sandbox->setSecurityPolicy(new SecurityPolicy([], ['escape']));

        $this->expectException(SecurityNotAllowedFilterError::class);
        $this->expectExceptionMessage('Filter "upper" is not allowed');
        $twig->render('t');
    }

    public function testSetSecurityPolicyLoosening()
    {
        list($twig, $sandbox) = $this->build(['t' => '{{ "foo"|upper }}'], new SecurityPolicy(), true);

        try {
            $twig->render('t');
            $this->fail('Expected SecurityNotAllowedFilterError on first render');
        } catch (SecurityNotAllowedFilterError $e) {
            $this->assertSame('upper', $e->getFilterName());
        }

        $sandbox->setSecurityPolicy(new SecurityPolicy([], ['upper']));

        $this->assertSame('FOO', $twig->render('t'));
    }

    public function testSourcePolicyDecisionFlip()
    {
        $sourcePolicy = new class() implements SourcePolicyInterface {
            public $sandboxFor = [];

            public function enableSandbox(Source $source): bool
            {
                return \in_array($source->getName(), $this->sandboxFor, true);
            }
        };

        list($twig) = $this->build(['t' => '{{ "foo"|upper }}'], new SecurityPolicy(), false, $sourcePolicy);

        $this->assertSame('FOO', $twig->render('t'));

        $sourcePolicy->sandboxFor = ['t'];

        $this->expectException(SecurityNotAllowedFilterError::class);
        $twig->render('t');
    }

    public function testPreWarmedParentTemplateThroughExtends()
    {
        $templates = [
            'parent.twig' => '{% block c %}default{% endblock %}{{ "hi"|upper }}',
            'child.twig' => '{% extends "parent.twig" %}{% block c %}child{% endblock %}',
        ];
        list($twig, $sandbox) = $this->build($templates, new SecurityPolicy(['extends', 'block']), false);

        // pre-warm with sandbox off: parent and child Template instances are now cached
        $this->assertSame('childHI', $twig->render('child.twig'));

        $sandbox->enableSandbox();

        $this->expectException(SecurityNotAllowedFilterError::class);
        $this->expectExceptionMessage('Filter "upper" is not allowed');
        $twig->render('child.twig');
    }

    public function testPreWarmedSharedViaSandboxedInclude()
    {
        $templates = [
            'top' => '{{ include("user", sandboxed=true) }}',
            'user' => '{% extends "shared" %}{% block c %}user{% endblock %}',
            'shared' => '{% block c %}{% endblock %}{{ "ok"|upper }}',
        ];
        list($twig) = $this->build($templates, new SecurityPolicy(['extends', 'block'], ['escape'], [], [], ['include']), false);

        // legitimate pre-warm of the trusted layout with sandbox off
        $this->assertSame('OK', $twig->render('shared'));

        // sandboxed include of user, which extends the pre-warmed shared:
        // upper is not in the allow-list, so it must throw even though shared
        // was loaded unsandboxed.
        $this->expectException(SecurityNotAllowedFilterError::class);
        $this->expectExceptionMessage('Filter "upper" is not allowed');
        $twig->render('top');
    }

    public function testMacroFromPreWarmedTemplate()
    {
        $templates = [
            'macros.twig' => '{% macro greet(name) %}{{ name|upper }}{% endmacro %}',
            'caller.twig' => '{% import "macros.twig" as m %}{{ m.greet("world") }}',
        ];
        list($twig, $sandbox) = $this->build($templates, new SecurityPolicy(['import', 'macro']), false);

        // pre-warm with sandbox off
        $this->assertSame('WORLD', $twig->render('caller.twig'));

        $sandbox->enableSandbox();

        $this->expectException(SecurityNotAllowedFilterError::class);
        $this->expectExceptionMessage('Filter "upper" is not allowed');
        $twig->render('caller.twig');
    }

    public function testSandboxTagAroundIncludeOfPreWarmedTemplate()
    {
        $templates = [
            'wrapper' => '{% sandbox %}{% include "user" %}{% endsandbox %}',
            'user' => '{% extends "shared" %}{% block c %}user{% endblock %}',
            'shared' => '{% block c %}{% endblock %}{{ "ok"|upper }}',
        ];
        list($twig) = $this->build($templates, new SecurityPolicy(['extends', 'block', 'include'], ['escape']), false);

        $this->assertSame('OK', $twig->render('shared'));

        $this->expectException(SecurityNotAllowedFilterError::class);
        $this->expectExceptionMessage('Filter "upper" is not allowed');
        $twig->render('wrapper');
    }

    public function testTagBypassThroughPreWarmedParent()
    {
        $templates = [
            'parent.twig' => '{% block c %}{% for i in 1..2 %}{{ i }}{% endfor %}{% endblock %}',
            'child.twig' => '{% extends "parent.twig" %}{% block c %}{{ parent() }}{% endblock %}',
        ];
        list($twig, $sandbox) = $this->build($templates, new SecurityPolicy(['extends', 'block'], [], [], [], ['parent', 'range']), false);

        $this->assertSame('12', $twig->render('child.twig'));

        $sandbox->enableSandbox();

        $this->expectException(SecurityNotAllowedTagError::class);
        $this->expectExceptionMessage('Tag "for" is not allowed');
        $twig->render('child.twig');
    }

    public function testFunctionBypassThroughPreWarmedParent()
    {
        $templates = [
            'parent.twig' => '{% block c %}{{ range(1, 2)|first }}{% endblock %}',
            'child.twig' => '{% extends "parent.twig" %}{% block c %}{{ parent() }}{% endblock %}',
        ];
        list($twig, $sandbox) = $this->build($templates, new SecurityPolicy(['extends', 'block'], ['first'], [], [], ['parent']), false);

        $this->assertSame('1', $twig->render('child.twig'));

        $sandbox->enableSandbox();

        $this->expectException(SecurityNotAllowedFunctionError::class);
        $this->expectExceptionMessage('Function "range" is not allowed');
        $twig->render('child.twig');
    }

    public function testDynamicParentFilterRejectedWhenReachedViaMacroImport()
    {
        // twig_call_macro() walks getParent() to find the macro on a parent template. When the
        // imported template has a dynamic {% extends %}, doGetParent() evaluates the user expression.
        // The sandbox security check must run on the imported template *before* doGetParent() runs.
        $templates = [
            'grandparent.twig' => '{% macro foo() %}grand{% endmacro %}',
            'middle.twig' => '{% extends parent_name|evil %}',
            'caller.twig' => '{% import "middle.twig" as m %}{{ m.foo() }}',
        ];
        list($twig) = $this->build($templates, new SecurityPolicy(['extends', 'import', 'macro']), true);
        $evilCalls = 0;
        $twig->addFilter(new TwigFilter('evil', function ($v) use (&$evilCalls) {
            ++$evilCalls;

            return $v;
        }));

        try {
            $twig->render('caller.twig', ['parent_name' => 'grandparent.twig']);
            $this->fail('Expected SecurityNotAllowedFilterError');
        } catch (SecurityNotAllowedFilterError $e) {
            $this->assertSame('evil', $e->getFilterName());
        }
        $this->assertSame(0, $evilCalls, 'The forbidden filter must not be invoked before the security check runs.');
    }

    public function testDynamicParentFunctionRejectedWhenReachedViaMacroImport()
    {
        $templates = [
            'grandparent.twig' => '{% macro foo() %}grand{% endmacro %}',
            'middle.twig' => '{% extends evil(parent_name) %}',
            'caller.twig' => '{% import "middle.twig" as m %}{{ m.foo() }}',
        ];
        list($twig) = $this->build($templates, new SecurityPolicy(['extends', 'import', 'macro']), true);
        $evilCalls = 0;
        $twig->addFunction(new TwigFunction('evil', function ($v) use (&$evilCalls) {
            ++$evilCalls;

            return $v;
        }));

        try {
            $twig->render('caller.twig', ['parent_name' => 'grandparent.twig']);
            $this->fail('Expected SecurityNotAllowedFunctionError');
        } catch (SecurityNotAllowedFunctionError $e) {
            $this->assertSame('evil', $e->getFunctionName());
        }
        $this->assertSame(0, $evilCalls, 'The forbidden function must not be invoked before the security check runs.');
    }

    public function testDynamicParentFilterRejectedOnPreWarmedTemplate()
    {
        $templates = [
            'grandparent.twig' => '{% macro foo() %}grand{% endmacro %}',
            'middle.twig' => '{% extends parent_name|evil %}',
            'caller.twig' => '{% import "middle.twig" as m %}{{ m.foo() }}',
        ];
        list($twig, $sandbox) = $this->build($templates, new SecurityPolicy(['extends', 'import', 'macro']), false);
        $evilCalls = 0;
        $twig->addFilter(new TwigFilter('evil', function ($v) use (&$evilCalls) {
            ++$evilCalls;

            return $v;
        }));

        $this->assertSame('grand', $twig->render('caller.twig', ['parent_name' => 'grandparent.twig']));
        $this->assertSame(1, $evilCalls);

        $sandbox->enableSandbox();

        try {
            $twig->render('caller.twig', ['parent_name' => 'grandparent.twig']);
            $this->fail('Expected SecurityNotAllowedFilterError');
        } catch (SecurityNotAllowedFilterError $e) {
            $this->assertSame('evil', $e->getFilterName());
        }
        $this->assertSame(1, $evilCalls, 'The forbidden filter must not be invoked after the sandbox is enabled.');
    }

    public function testRepeatedRendersInStableSandboxedStateRunCheckEachTime()
    {
        $counter = new CountingSecurityPolicy(new SecurityPolicy([], ['upper']));
        list($twig) = $this->build(['t' => '{{ "x"|upper }}'], $counter, true);

        for ($i = 0; $i < 5; ++$i) {
            $this->assertSame('X', $twig->render('t'));
        }

        // The per-render check is intentionally re-run on every display: the cached verdict was
        // the source of the original bypass.
        $this->assertGreaterThanOrEqual(5, $counter->callCount);
    }

    public function testNoCheckWhenSandboxRemainsOff()
    {
        $counter = new CountingSecurityPolicy(new SecurityPolicy());
        list($twig) = $this->build(['t' => '{{ "x"|upper }}'], $counter, false);

        for ($i = 0; $i < 5; ++$i) {
            $this->assertSame('X', $twig->render('t'));
        }

        $this->assertSame(0, $counter->callCount);
    }

    private function build(array $templates, SecurityPolicyInterface $policy, bool $sandboxed, ?SourcePolicyInterface $sourcePolicy = null): array
    {
        $twig = new Environment(new ArrayLoader($templates), ['cache' => false, 'autoescape' => false]);
        $sandbox = new SandboxExtension($policy, $sandboxed, $sourcePolicy);
        $twig->addExtension($sandbox);

        return [$twig, $sandbox];
    }
}

class CountingSecurityPolicy implements SecurityPolicyInterface
{
    public $callCount = 0;
    private $inner;

    public function __construct(SecurityPolicyInterface $inner)
    {
        $this->inner = $inner;
    }

    public function checkSecurity($tags, $filters, $functions): void
    {
        ++$this->callCount;
        $this->inner->checkSecurity($tags, $filters, $functions);
    }

    public function checkMethodAllowed($obj, $method): void
    {
        $this->inner->checkMethodAllowed($obj, $method);
    }

    public function checkPropertyAllowed($obj, $property): void
    {
        $this->inner->checkPropertyAllowed($obj, $property);
    }
}
