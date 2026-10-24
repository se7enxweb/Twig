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
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Extension\SandboxExtension;
use Twig\Extension\StringLoaderExtension;
use Twig\Loader\ArrayLoader;
use Twig\Sandbox\SecurityError;
use Twig\Sandbox\SecurityNotAllowedFilterError;
use Twig\Sandbox\SecurityNotAllowedFunctionError;
use Twig\Sandbox\SecurityNotAllowedMethodError;
use Twig\Sandbox\SecurityNotAllowedPropertyError;
use Twig\Sandbox\SecurityNotAllowedTagError;
use Twig\Sandbox\SecurityPolicy;
use Twig\Sandbox\SourcePolicyInterface;
use Twig\Node\BodyNode;
use Twig\Node\CheckToStringNode;
use Twig\Node\CoercesChildrenToStringInterface;
use Twig\Node\Expression\AbstractExpression;
use Twig\Node\Expression\NameExpression;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\Node\PrintNode;
use Twig\NodeTraverser;
use Twig\NodeVisitor\SandboxNodeVisitor;
use Twig\Source;
use Twig\TwigFilter;
use Twig\TwigFunction;

class SandboxTest extends TestCase
{
    protected static $params;
    protected static $templates;

    protected function setUp(): void
    {
        self::$params = [
            'name' => 'Fabien',
            'obj' => new FooObject(),
            'arr' => ['obj' => new FooObject()],
            'child_obj' => new ChildClass(),
            'some_array' => [5, 6, 7, new FooObject()],
            'array_like' => new ArrayLikeObject(),
            'magic' => new MagicObject(),
            'recursion' => [4],
            'iterator' => new \ArrayIterator(['a', new FooObject()]),
            'iterator_map' => new \ArrayIterator(['__toString' => new FooObject()]),
            'iterator_nested' => new \ArrayIterator(['a', new \ArrayIterator(['b', new FooObject()])]),
            'stringable_iterator' => new StringableTraversableObject(['a', new FooObject()]),
            'stringable_iterator_map' => new StringableTraversableObject(['__toString' => new FooObject()]),
        ];
        self::$params['recursion'][] = &self::$params['recursion'];
        self::$params['recursion'][] = new FooObject();

        self::$templates = [
            '1_basic1' => '{{ obj.foo }}',
            '1_basic2' => '{{ name|upper }}',
            '1_basic3' => '{% if name %}foo{% endif %}',
            '1_basic4' => '{{ obj.bar }}',
            '1_basic5' => '{{ obj }}',
            '1_basic7' => '{{ cycle(["foo","bar"], 1) }}',
            '1_basic8' => '{{ obj.getfoobar }}{{ obj.getFooBar }}',
            '1_basic9' => '{{ obj.foobar }}{{ obj.fooBar }}',
            '1_basic' => '{% if obj.foo %}{{ obj.foo|upper }}{% endif %}',
            '1_layout' => '{% block content %}{% endblock %}',
            '1_child' => "{% extends \"1_layout\" %}\n{% block content %}\n{{ \"a\"|json_encode }}\n{% endblock %}",
            '1_include' => '{{ include("1_basic1", sandboxed=true) }}',
            '1_basic2_include_template_from_string_sandboxed' => '{{ include(template_from_string("{{ name|upper }}"), sandboxed=true) }}',
            '1_basic2_include_template_from_string' => '{{ include(template_from_string("{{ name|upper }}")) }}',
            '1_range_operator' => '{{ (1..2)[0] }}',
            '1_syntax_error_wrapper' => '{% sandbox %}{% include "1_syntax_error" %}{% endsandbox %}',
            '1_syntax_error' => '{% syntax error }}',
            '1_childobj_parentmethod' => '{{ child_obj.ParentMethod() }}',
            '1_childobj_childmethod' => '{{ child_obj.ChildMethod() }}',
            '1_array_like' => '{{ array_like["foo"] }}',
        ];
    }

    public function testSandboxWithInheritance()
    {
        $this->expectException(SecurityError::class);
        $this->expectExceptionMessage('Filter "json_encode" is not allowed in "1_child" at line 3.');

        $twig = $this->getEnvironment(true, [], self::$templates, ['block']);
        $twig->load('1_child')->render([]);
    }

    public function testSandboxGloballySet()
    {
        $twig = $this->getEnvironment(false, [], self::$templates);
        $this->assertEquals('FOO', $twig->load('1_basic')->render(self::$params), 'Sandbox does nothing if it is disabled globally');
    }

    public function testSandboxUnallowedPropertyAccessor()
    {
        $twig = $this->getEnvironment(true, [], self::$templates);
        try {
            $twig->load('1_basic1')->render(['obj' => new MagicObject()]);
            $this->fail('Sandbox throws a SecurityError exception if an unallowed method is called');
        } catch (SecurityNotAllowedPropertyError $e) {
            $this->assertEquals('Twig\Tests\Extension\MagicObject', $e->getClassName(), 'Exception should be raised on the "Twig\Tests\Extension\MagicObject" class');
            $this->assertEquals('foo', $e->getPropertyName(), 'Exception should be raised on the "foo" property');
        }
    }

    public function testSandboxUnallowedMethodAccessorReportsTheProperty()
    {
        $twig = $this->getEnvironment(true, [], self::$templates);
        try {
            $twig->load('1_basic1')->render(self::$params);
            $this->fail('Sandbox throws a SecurityError exception if an unallowed method is called');
        } catch (SecurityError $e) {
            $this->assertInstanceOf(SecurityNotAllowedPropertyError::class, $e, 'The property check runs first and its error is reported when the method is not allowed either');
            $this->assertEquals('Twig\Tests\Extension\FooObject', $e->getClassName(), 'Exception should be raised on the "Twig\Tests\Extension\FooObject" class');
            $this->assertEquals('foo', $e->getPropertyName(), 'Exception should be raised on the "foo" property');
        }
    }

    public function testSandboxUnallowedArrayIndexAccessor()
    {
        $twig = $this->getEnvironment(true, [], self::$templates);

        // ArrayObject and other internal array-like classes are exempted from sandbox restrictions
        $this->assertSame('bar', $twig->load('1_array_like')->render(['array_like' => new \ArrayObject(['foo' => 'bar'])]));

        try {
            $twig->load('1_array_like')->render(self::$params);
            $this->fail('Sandbox throws a SecurityError exception if an unallowed method is called');
        } catch (SecurityNotAllowedPropertyError $e) {
            $this->assertEquals('Twig\Tests\Extension\ArrayLikeObject', $e->getClassName(), 'Exception should be raised on the "Twig\Tests\Extension\ArrayLikeObject" class');
            $this->assertEquals('foo', $e->getPropertyName(), 'Exception should be raised on the "foo" property');
        }
    }

    public function testSandboxUnallowedArrayIndexAccessorInStrictMode()
    {
        $twig = $this->getEnvironment(true, ['strict_variables' => true], self::$templates);

        try {
            $twig->load('1_array_like')->render(self::$params);
            $this->fail('Sandbox throws a SecurityError exception if an unallowed method is called');
        } catch (SecurityNotAllowedPropertyError $e) {
            $this->assertEquals('Twig\Tests\Extension\ArrayLikeObject', $e->getClassName(), 'Exception should be raised on the "Twig\Tests\Extension\ArrayLikeObject" class');
            $this->assertEquals('foo', $e->getPropertyName(), 'Exception should be raised on the "foo" property');
        }
    }

    public function testIfSandBoxIsDisabledAfterSyntaxError()
    {
        $twig = $this->getEnvironment(false, [], self::$templates);
        try {
            $twig->load('1_syntax_error_wrapper')->render(self::$params);
        } catch (SyntaxError $e) {
            /** @var SandboxExtension $sandbox */
            $sandbox = $twig->getExtension(SandboxExtension::class);
            $this->assertFalse($sandbox->isSandboxed());
        }
    }

    public function testSandboxGloballyFalseUnallowedFilterWithIncludeTemplateFromStringSandboxed()
    {
        $twig = $this->getEnvironment(false, [], self::$templates);
        $twig->addExtension(new StringLoaderExtension());
        try {
            $twig->load('1_basic2_include_template_from_string_sandboxed')->render(self::$params);
            $this->fail('Sandbox throws a SecurityError exception if an unallowed filter is called');
        } catch (SecurityError $e) {
            $this->assertInstanceOf('\Twig\Sandbox\SecurityNotAllowedFilterError', $e, 'Exception should be an instance of Twig_Sandbox_SecurityNotAllowedFilterError');
            $this->assertEquals('upper', $e->getFilterName(), 'Exception should be raised on the "upper" filter');
        }
    }

    public function testSandboxGloballyTrueUnallowedFilterWithIncludeTemplateFromStringSandboxed()
    {
        $twig = $this->getEnvironment(true, [], self::$templates, [], [], [], [], ['include', 'template_from_string']);
        $twig->addExtension(new StringLoaderExtension());
        try {
            $twig->load('1_basic2_include_template_from_string_sandboxed')->render(self::$params);
            $this->fail('Sandbox throws a SecurityError exception if an unallowed filter is called');
        } catch (SecurityError $e) {
            $this->assertInstanceOf('\Twig\Sandbox\SecurityNotAllowedFilterError', $e, 'Exception should be an instance of Twig_Sandbox_SecurityNotAllowedFilterError');
            $this->assertEquals('upper', $e->getFilterName(), 'Exception should be raised on the "upper" filter');
        }
    }

    public function testSandboxGloballyFalseUnallowedFilterWithIncludeTemplateFromStringNotSandboxed()
    {
        $twig = $this->getEnvironment(false, [], self::$templates);
        $twig->addExtension(new StringLoaderExtension());
        $this->assertSame('FABIEN', $twig->load('1_basic2_include_template_from_string')->render(self::$params));
    }

    public function testSandboxGloballyTrueUnallowedFilterWithIncludeTemplateFromStringNotSandboxed()
    {
        $twig = $this->getEnvironment(true, [], self::$templates, [], [], [], [], ['include', 'template_from_string']);
        $twig->addExtension(new StringLoaderExtension());
        try {
            $twig->load('1_basic2_include_template_from_string')->render(self::$params);
            $this->fail('Sandbox throws a SecurityError exception if an unallowed filter is called');
        } catch (SecurityError $e) {
            $this->assertInstanceOf('\Twig\Sandbox\SecurityNotAllowedFilterError', $e, 'Exception should be an instance of Twig_Sandbox_SecurityNotAllowedFilterError');
            $this->assertEquals('upper', $e->getFilterName(), 'Exception should be raised on the "upper" filter');
        }
    }

    public function testSandboxUnallowedFilter()
    {
        $twig = $this->getEnvironment(true, [], self::$templates);
        try {
            $twig->load('1_basic2')->render(self::$params);
            $this->fail('Sandbox throws a SecurityError exception if an unallowed filter is called');
        } catch (SecurityError $e) {
            $this->assertInstanceOf(SecurityNotAllowedFilterError::class, $e, 'Exception should be an instance of Twig_Sandbox_SecurityNotAllowedFilterError');
            $this->assertEquals('upper', $e->getFilterName(), 'Exception should be raised on the "upper" filter');
        }
    }

    public function testSandboxUnallowedTag()
    {
        $twig = $this->getEnvironment(true, [], self::$templates);
        try {
            $twig->load('1_basic3')->render(self::$params);
            $this->fail('Sandbox throws a SecurityError exception if an unallowed tag is used in the template');
        } catch (SecurityError $e) {
            $this->assertInstanceOf(SecurityNotAllowedTagError::class, $e, 'Exception should be an instance of Twig_Sandbox_SecurityNotAllowedTagError');
            $this->assertEquals('if', $e->getTagName(), 'Exception should be raised on the "if" tag');
        }
    }

    public function testSandboxUnallowedProperty()
    {
        $twig = $this->getEnvironment(true, [], self::$templates);
        try {
            $twig->load('1_basic4')->render(self::$params);
            $this->fail('Sandbox throws a SecurityError exception if an unallowed property is called in the template');
        } catch (SecurityError $e) {
            $this->assertInstanceOf(SecurityNotAllowedPropertyError::class, $e, 'Exception should be an instance of Twig_Sandbox_SecurityNotAllowedPropertyError');
            $this->assertEquals('Twig\Tests\Extension\FooObject', $e->getClassName(), 'Exception should be raised on the "Twig\Tests\Extension\FooObject" class');
            $this->assertEquals('bar', $e->getPropertyName(), 'Exception should be raised on the "bar" property');
        }
    }

    /**
     * @dataProvider getSandboxUnallowedToStringTests
     */
    public function testSandboxUnallowedToString($template)
    {
        $twig = $this->getEnvironment(true, [], ['index' => $template], ['if', 'do', 'for', 'set'], ['upper', 'join', 'replace', 'format', 'split'], ['Twig\Tests\Extension\FooObject' => 'getAnotherFooObject'], [], ['random', 'range', 'my_func']);
        $twig->addFunction(new TwigFunction('my_func', function ($a) { return (string) $a; }));
        try {
            $twig->load('index')->render(self::$params);
            $this->fail('Sandbox throws a SecurityError exception if an unallowed method (__toString()) is called in the template');
        } catch (SecurityError $e) {
            $this->assertInstanceOf(SecurityNotAllowedMethodError::class, $e, 'Exception should be an instance of Twig_Sandbox_SecurityNotAllowedMethodError');
            $this->assertEquals('Twig\Tests\Extension\FooObject', $e->getClassName(), 'Exception should be raised on the "Twig\Tests\Extension\FooObject" class');
            $this->assertEquals('__tostring', $e->getMethodName(), 'Exception should be raised on the "__toString" method');
        }
    }

    public function getSandboxUnallowedToStringTests()
    {
        return [
            'simple' => ['{{ obj }}'],
            'object_from_array' => ['{{ arr.obj }}'],
            'object_chain' => ['{{ obj.anotherFooObject }}'],
            'filter' => ['{{ obj|upper }}'],
            'filter_from_array' => ['{{ arr.obj|upper }}'],
            'function' => ['{{ random(obj) }}'],
            'function_from_array' => ['{{ random(arr.obj) }}'],
            'function_and_filter' => ['{{ random(obj|upper) }}'],
            'function_and_filter_from_array' => ['{{ random(arr.obj|upper) }}'],
            'object_chain_and_filter' => ['{{ obj.anotherFooObject|upper }}'],
            'object_chain_and_function' => ['{{ random(obj.anotherFooObject) }}'],
            'concat' => ['{{ obj ~ "" }}'],
            'concat_again' => ['{{ "" ~ obj }}'],
            'object_in_arguments' => ['{{ "__toString"|replace({"__toString": obj}) }}'],
            'object_in_array' => ['{{ [12, "foo", obj]|join(", ") }}'],
            'object_in_array_var' => ['{{ some_array|join(", ") }}'],
            'object_in_array_nested' => ['{{ [12, "foo", [12, "foo", obj]]|join(", ") }}'],
            'object_in_array_var_nested' => ['{{ [12, "foo", some_array]|join(", ") }}'],
            'object_in_array_dynamic_key' => ['{{ {(obj): "foo"}|join(", ") }}'],
            'object_in_array_dynamic_key_nested' => ['{{ {"foo": { (obj): "foo" }}|join(", ") }}'],
            'context' => ['{{ _context|join(", ") }}'],
            'recursion' => ['{{ recursion|join(", ") }}'],
            'iterator_in_join' => ['{{ iterator|join(", ") }}'],
            'iterator_nested_in_join' => ['{{ iterator_nested|join(", ") }}'],
            'iterator_in_replace' => ['{{ "__toString"|replace(iterator_map) }}'],
            'ternary_print' => ['{{ true ? obj : "" }}'],
            'ternary_filter_input' => ['{{ (true ? obj : "")|upper }}'],
            'elvis_filter_input' => ['{{ (obj ?: "")|upper }}'],
            'nullcoalesce_filter_input' => ['{{ (obj ?? "")|upper }}'],
            'function_arg_with_ternary' => ['{{ random(true ? obj : "") }}'],
            'filter_arg_with_ternary' => ['{{ "%s"|format(true ? obj : "") }}'],
            'matches_in_print' => ['{{ obj matches "/foo/" ? "1" : "0" }}'],
            'equal_in_print' => ['{{ obj == "x" ? "1" : "0" }}'],
            'equal_in_if' => ['{% if obj == "x" %}LEAK{% endif %}'],
            'notequal_in_if' => ['{% if obj != "x" %}LEAK{% endif %}'],
            'spaceship_in_if' => ['{% if (obj <=> "x") == 0 %}LEAK{% endif %}'],
            'less_in_if' => ['{% if obj < "B" %}LEAK{% endif %}'],
            'greater_in_if' => ['{% if obj > "A" %}LEAK{% endif %}'],
            'lessequal_in_if' => ['{% if obj <= "z" %}LEAK{% endif %}'],
            'greaterequal_in_if' => ['{% if obj >= "a" %}LEAK{% endif %}'],
            'concat_left_in_if' => ['{% if obj ~ "" %}LEAK{% endif %}'],
            'concat_right_in_if' => ['{% if "" ~ obj %}LEAK{% endif %}'],
            'range_left' => ['{% for x in obj..1 %}LEAK{% endfor %}'],
            'range_right' => ['{% for x in 1..obj %}LEAK{% endfor %}'],
            'in_array_right' => ['{% if "needle" in [obj] %}LEAK{% endif %}'],
            'in_array_left' => ['{% if obj in ["needle"] %}LEAK{% endif %}'],
            'notin_array_right' => ['{% if "needle" not in [obj] %}LEAK{% endif %}'],
            'notin_array_left' => ['{% if obj not in ["needle"] %}LEAK{% endif %}'],
            'in_iterator_right' => ['{% if "needle" in iterator %}LEAK{% endif %}'],
            'notin_iterator_right' => ['{% if "needle" not in iterator %}LEAK{% endif %}'],
            'do_tag_function_arg' => ['{% do my_func(obj) %}'],
            'do_tag_filter_input' => ['{% do obj|upper %}'],
            'do_tag_concat' => ['{% do obj ~ "" %}'],
            'set_tag_filter_input' => ['{% set _ = obj|upper %}'],
            'set_tag_concat' => ['{% set _ = obj ~ "" %}'],
            'set_tag_array_dynamic_key' => ['{% set _ = {(obj): "v"} %}'],
            'set_tag_array_dynamic_key_nested' => ['{% set _ = {"foo": {(obj): "v"}} %}'],
            'set_tag_array_dynamic_key_object_chain' => ['{% set _ = {(obj.anotherFooObject): "v"} %}'],
            'set_capture_print' => ['{% set _ %}{{ obj }}{% endset %}'],
            'is_empty_in_if' => ['{% if obj is empty %}LEAK{% endif %}'],
            'is_empty_in_print' => ['{{ obj is empty ? "1" : "0" }}'],
            'method_argument' => ['{{ obj.foo(obj.anotherFooObject) }}'],
            'filter_input_in_if' => ['{% if obj|upper == "X" %}LEAK{% endif %}'],
            'filter_arg_in_if' => ['{% if "x"|replace({"x": obj}) == "y" %}LEAK{% endif %}'],
            'function_arg_in_if' => ['{% if not random(obj) %}LEAK{% endif %}'],
            'filter_input_in_for' => ['{% for x in (obj|split(",")) %}LEAK{% endfor %}'],
            'function_arg_in_for' => ['{% for x in [random(obj)] %}LEAK{% endfor %}'],
        ];
    }

    public function testSandboxBlocksToStringOnFunctionReturn()
    {
        $twig = $this->getEnvironment(true, [], ['index' => '{{ make_obj() }}'], [], [], [], [], ['make_obj']);
        $twig->addFunction(new TwigFunction('make_obj', function () { return new FooObject(); }));
        try {
            $twig->load('index')->render([]);
            $this->fail('Sandbox throws a SecurityError exception if __toString is called on the return of an allowed function');
        } catch (SecurityNotAllowedMethodError $e) {
            $this->assertEquals('Twig\Tests\Extension\FooObject', $e->getClassName());
            $this->assertEquals('__tostring', $e->getMethodName());
        }
    }

    public function testSandboxBlocksToStringOnFilterReturn()
    {
        $twig = $this->getEnvironment(true, [], ['index' => '{{ "x"|to_obj }}'], [], ['to_obj']);
        $twig->addFilter(new TwigFilter('to_obj', function () { return new FooObject(); }));
        try {
            $twig->load('index')->render([]);
            $this->fail('Sandbox throws a SecurityError exception if __toString is called on the return of an allowed filter');
        } catch (SecurityNotAllowedMethodError $e) {
            $this->assertEquals('Twig\Tests\Extension\FooObject', $e->getClassName());
            $this->assertEquals('__tostring', $e->getMethodName());
        }
    }

    public function testSandboxBlocksToStringOnDynamicAttributeName()
    {
        $twig = $this->getEnvironment(true, ['strict_variables' => true], ['index' => '{{ arr[obj] }}'], [], [], ['Twig\Tests\Extension\FooObject' => 'getAnotherFooObject']);
        try {
            $twig->load('index')->render(self::$params);
            $this->fail('Sandbox throws a SecurityError exception if __toString is called on a dynamic attribute name');
        } catch (SecurityNotAllowedMethodError $e) {
            $this->assertEquals('Twig\Tests\Extension\FooObject', $e->getClassName());
            $this->assertEquals('__tostring', $e->getMethodName());
        }
    }

    public function testSandboxBlocksToStringOnIncludeTemplateName()
    {
        $twig = $this->getEnvironment(true, [], ['index' => '{% include obj %}'], ['include']);
        try {
            $twig->load('index')->render(self::$params);
            $this->fail('Sandbox throws a SecurityError exception if __toString is called on an include template name');
        } catch (SecurityNotAllowedMethodError $e) {
            $this->assertEquals('Twig\Tests\Extension\FooObject', $e->getClassName());
            $this->assertEquals('__tostring', $e->getMethodName());
        }
    }

    public function testSandboxBlocksToStringOnExtendsTemplateName()
    {
        $twig = $this->getEnvironment(true, [], ['index' => '{% extends obj %}'], ['extends']);
        try {
            $twig->load('index')->render(self::$params);
            $this->fail('Sandbox throws a SecurityError exception if __toString is called on an extends template name');
        } catch (SecurityNotAllowedMethodError $e) {
            $this->assertEquals('Twig\Tests\Extension\FooObject', $e->getClassName());
            $this->assertEquals('__tostring', $e->getMethodName());
        }
    }

    public function testSandboxBlocksToStringOnBlockFunctionTemplateName()
    {
        $twig = $this->getEnvironment(true, [], ['index' => '{{ block("content", obj) }}'], [], [], [], [], ['block']);
        try {
            $twig->load('index')->render(self::$params);
            $this->fail('Sandbox throws a SecurityError exception if __toString is called on a block() template argument');
        } catch (SecurityNotAllowedMethodError $e) {
            $this->assertEquals('Twig\Tests\Extension\FooObject', $e->getClassName());
            $this->assertEquals('__tostring', $e->getMethodName());
        }
    }

    public function testSandboxBlocksToStringOnEmbedTemplateName()
    {
        $twig = $this->getEnvironment(true, [], ['index' => '{% embed obj %}{% endembed %}'], ['embed', 'extends']);
        try {
            $twig->load('index')->render(self::$params);
            $this->fail('Sandbox throws a SecurityError exception if __toString is called on an embed template name');
        } catch (SecurityNotAllowedMethodError $e) {
            $this->assertEquals('Twig\Tests\Extension\FooObject', $e->getClassName());
            $this->assertEquals('__tostring', $e->getMethodName());
        }
    }

    public function testSandboxBlocksToStringOnIsConstantTestArgument()
    {
        $twig = $this->getEnvironment(true, [], ['index' => '{% if "x" is constant(obj) %}LEAK{% endif %}'], ['if']);
        try {
            $twig->load('index')->render(self::$params);
            $this->fail('Sandbox throws a SecurityError exception if __toString is called on a constant test argument');
        } catch (SecurityNotAllowedMethodError $e) {
            $this->assertEquals('Twig\Tests\Extension\FooObject', $e->getClassName());
            $this->assertEquals('__tostring', $e->getMethodName());
        }
    }

    public function testSandboxBlocksToStringOnDeprecatedMessage()
    {
        $twig = $this->getEnvironment(true, [], ['index' => '{% deprecated obj %}'], ['deprecated']);
        set_error_handler(function () { return true; }, \E_USER_DEPRECATED);
        try {
            $twig->load('index')->render(self::$params);
            $this->fail('Sandbox throws a SecurityError exception if __toString is called on a deprecated tag message');
        } catch (SecurityNotAllowedMethodError $e) {
            $this->assertEquals('Twig\Tests\Extension\FooObject', $e->getClassName());
            $this->assertEquals('__tostring', $e->getMethodName());
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @dataProvider getTraversableBypassTemplates
     */
    public function testSourcePolicySandboxBlocksToStringInTraversable($template, $filter)
    {
        $sourcePolicy = new class() implements SourcePolicyInterface {
            public function enableSandbox(Source $source): bool
            {
                return true;
            }
        };

        $twig = $this->getEnvironment(false, [], ['index' => $template], [], [$filter], [], [], [], $sourcePolicy);

        try {
            $twig->load('index')->render(self::$params);
            $this->fail('Sandbox should block __toString on objects contained in a Traversable passed to the "'.$filter.'" filter (SourcePolicyInterface).');
        } catch (SecurityNotAllowedMethodError $e) {
            $this->assertSame('Twig\Tests\Extension\FooObject', $e->getClassName());
            $this->assertSame('__tostring', $e->getMethodName());
        }
    }

    public function getTraversableBypassTemplates()
    {
        return [
            'join' => ['{{ iterator|join(", ") }}', 'join'],
            'replace' => ['{{ "__toString"|replace(iterator_map) }}', 'replace'],
        ];
    }

    /**
     * @dataProvider getStringableTraversableBypassTemplates
     */
    public function testSandboxBlocksToStringInStringableTraversable($template)
    {
        $twig = $this->getEnvironment(true, [], ['index' => $template], [], ['join', 'replace'], ['Twig\Tests\Extension\StringableTraversableObject' => ['__tostring']]);

        try {
            $twig->load('index')->render(self::$params);
            $this->fail('Sandbox should block __toString on objects yielded by a Stringable+Traversable container, even when the container\'s own __toString is allowed.');
        } catch (SecurityNotAllowedMethodError $e) {
            $this->assertSame('Twig\Tests\Extension\FooObject', $e->getClassName());
            $this->assertSame('__tostring', $e->getMethodName());
        }
    }

    public function getStringableTraversableBypassTemplates()
    {
        return [
            'join' => ['{{ stringable_iterator|join(", ") }}'],
            'replace' => ['{{ "__toString"|replace(stringable_iterator_map) }}'],
        ];
    }

    /**
     * @dataProvider getStringableTraversableBypassTemplates
     */
    public function testSourcePolicySandboxBlocksToStringInStringableTraversable($template)
    {
        $sourcePolicy = new class() implements SourcePolicyInterface {
            public function enableSandbox(Source $source): bool
            {
                return true;
            }
        };

        $twig = $this->getEnvironment(false, [], ['index' => $template], [], ['join', 'replace'], ['Twig\Tests\Extension\StringableTraversableObject' => ['__tostring']], [], [], $sourcePolicy);

        try {
            $twig->load('index')->render(self::$params);
            $this->fail('Sandbox should block __toString on objects yielded by a Stringable+Traversable container under a SourcePolicyInterface-only sandbox.');
        } catch (SecurityNotAllowedMethodError $e) {
            $this->assertSame('Twig\Tests\Extension\FooObject', $e->getClassName());
            $this->assertSame('__tostring', $e->getMethodName());
        }
    }

    public function testSandboxAllowsPrintingStringableTraversableWhenToStringAllowed()
    {
        $twig = $this->getEnvironment(true, ['autoescape' => 'html'], ['index' => '{{ obj }}'], [], ['escape'], ['Twig\Tests\Extension\StringableTraversableObject' => ['__tostring']]);

        $params = ['obj' => new StringableTraversableObject(['a', 'b'])];

        $this->assertSame('stringable-traversable', $twig->load('index')->render($params));
    }

    /**
     * @dataProvider getCyclicTraversableTemplates
     */
    public function testSandboxHandlesCyclicTraversableWithoutStackOverflow($template)
    {
        // A self-referencing IteratorAggregate must not make the sandbox policy walker recurse
        // infinitely; PHP itself then fails cleanly when the object reaches implode()/str_replace().
        $twig = $this->getEnvironment(true, [], ['index' => $template], [], ['join', 'replace']);

        try {
            $twig->load('index')->render(['obj' => new CyclicTraversableObject()]);
            $this->fail('The object cannot be converted to a string');
        } catch (\Throwable $e) {
            $this->assertNotInstanceOf(SecurityError::class, $e);
            $this->assertStringContainsString('could not be converted to string', $e->getMessage());
        }
    }

    public function getCyclicTraversableTemplates()
    {
        return [
            'join' => ['{{ obj|join(",") }}'],
            'replace' => ['{{ "x"|replace(obj) }}'],
        ];
    }

    public function testSandboxPreservesTraversableArgumentIdentity()
    {
        // A typed Traversable argument (e.g. Symfony's FormView) must reach host code as-is, not as a plain array.
        $twig = $this->getEnvironment(true, [], ['index' => '{{ render_traversable(obj) }}'], [], [], ['Twig\Tests\Extension\StringableTraversableObject' => ['__tostring']], [], ['render_traversable']);
        $twig->addFunction(new TwigFunction('render_traversable', function ($obj) {
            if (!$obj instanceof StringableTraversableObject) {
                throw new \RuntimeException(sprintf('Expected a StringableTraversableObject, got "%s".', \is_object($obj) ? \get_class($obj) : \gettype($obj)));
            }

            return (string) $obj;
        }));

        $params = ['obj' => new StringableTraversableObject(['a', 'b'])];
        $this->assertSame('stringable-traversable', $twig->load('index')->render($params));
    }

    public function testSandboxStillBlocksDisallowedToStringInTraversableArgument()
    {
        $twig = $this->getEnvironment(true, [], ['index' => '{{ render_traversable(stringable_iterator) }}'], [], [], ['Twig\Tests\Extension\StringableTraversableObject' => ['__tostring']], [], ['render_traversable']);
        $twig->addFunction(new TwigFunction('render_traversable', function ($obj) { return (string) $obj; }));

        try {
            $twig->load('index')->render(self::$params);
            $this->fail('Sandbox should block __toString on objects yielded by a Traversable argument to a user function.');
        } catch (SecurityNotAllowedMethodError $e) {
            $this->assertSame('Twig\Tests\Extension\FooObject', $e->getClassName());
            $this->assertSame('__tostring', $e->getMethodName());
        }
    }

    public function testSandboxKeepsSelfImportShortcut()
    {
        $tpl = "{% macro local_lower(s) %}{{ s|lower }}{% endmacro %}{% from _self import local_lower %}{{ local_lower('A') }}";
        $twig = $this->getEnvironment(true, [], ['index' => $tpl], ['from', 'macro', 'import'], ['lower']);

        $this->assertSame('a', $twig->load('index')->render([]));
    }

    public function testCustomNodeImplementingCoercesChildrenToStringInterfaceIsWrapped()
    {
        $env = new Environment(new ArrayLoader());
        $custom = new CustomCoercingExpression(new NameExpression('foo', 1), new NameExpression('bar', 1), 1);
        $node = new ModuleNode(new BodyNode([new PrintNode($custom, 1)]), null, new Node(), new Node(), new Node(), [], new Source('foo', 'foo'));
        $traverser = new NodeTraverser($env, [new SandboxNodeVisitor()]);
        $node = $traverser->traverse($node);

        $custom = $node->getNode('body')->getNode(0)->getNode('expr');
        $this->assertInstanceOf(CheckToStringNode::class, $custom->getNode('left'));
        $this->assertInstanceOf(CheckToStringNode::class, $custom->getNode('right'));
    }

    public function testSelfIsNeverWrapped()
    {
        $env = new Environment(new ArrayLoader());
        $custom = new CustomCoercingNode(['expr' => new NameExpression('_self', 1)], [], 1);
        $node = new ModuleNode(new BodyNode([$custom]), null, new Node(), new Node(), new Node(), [], new Source('foo', 'foo'));
        $traverser = new NodeTraverser($env, [new SandboxNodeVisitor()]);
        $node = $traverser->traverse($node);

        $this->assertNotInstanceOf(CheckToStringNode::class, $node->getNode('body')->getNode(0)->getNode('expr'));
    }

    /**
     * @dataProvider getSandboxAllowedToStringTests
     */
    public function testSandboxAllowedToString($template, $output)
    {
        $twig = $this->getEnvironment(true, [], ['index' => $template], ['set', 'do'], [], ['Twig\Tests\Extension\FooObject' => ['foo', 'getAnotherFooObject']]);
        $this->assertEquals($output, $twig->load('index')->render(self::$params));
    }

    public function getSandboxAllowedToStringTests()
    {
        return [
            'constant_test' => ['{{ obj is constant("PHP_INT_MAX") }}', ''],
            'set_object' => ['{% set a = obj.anotherFooObject %}{{ a.foo }}', 'foo'],
            'do_object_discarded' => ['{% do obj %}', ''],
            'set_object_assigned' => ['{% set a = obj %}{{ a is defined ? "1" : "0" }}', '1'],
            'is_defined1' => ['{{ obj.anotherFooObject is defined }}', '1'],
            'is_defined2' => ['{{ magic.foo is defined }}', ''],
            'is_null' => ['{{ obj is null }}', ''],
            'is_sameas' => ['{{ obj is same as(obj) }}', '1'],
            'is_sameas_no_brackets' => ['{{ obj is same as obj }}', '1'],
            'is_sameas_from_array' => ['{{ arr.obj is same as(arr.obj) }}', '1'],
            'is_sameas_from_array_no_brackets' => ['{{ arr.obj is same as arr.obj }}', '1'],
            'is_sameas_from_another_method' => ['{{ obj.anotherFooObject is same as(obj.anotherFooObject) }}', ''],
            'is_sameas_from_another_method_no_brackets' => ['{{ obj.anotherFooObject is same as obj.anotherFooObject }}', ''],
        ];
    }

    public function testSandboxAllowMethodToString()
    {
        $twig = $this->getEnvironment(true, [], self::$templates, [], [], ['Twig\Tests\Extension\FooObject' => '__toString']);
        FooObject::reset();
        $this->assertEquals('foo', $twig->load('1_basic5')->render(self::$params), 'Sandbox allow some methods');
        $this->assertEquals(1, FooObject::$called['__toString'], 'Sandbox only calls method once');
    }

    public function testSandboxAllowMethodToStringDisabled()
    {
        $twig = $this->getEnvironment(false, [], self::$templates);
        FooObject::reset();
        $this->assertEquals('foo', $twig->load('1_basic5')->render(self::$params), 'Sandbox allows __toString when sandbox disabled');
        $this->assertEquals(1, FooObject::$called['__toString'], 'Sandbox only calls method once');
    }

    public function testSandboxUnallowedFunction()
    {
        $twig = $this->getEnvironment(true, [], self::$templates);
        try {
            $twig->load('1_basic7')->render(self::$params);
            $this->fail('Sandbox throws a SecurityError exception if an unallowed function is called in the template');
        } catch (SecurityError $e) {
            $this->assertInstanceOf(SecurityNotAllowedFunctionError::class, $e, 'Exception should be an instance of Twig_Sandbox_SecurityNotAllowedFunctionError');
            $this->assertEquals('cycle', $e->getFunctionName(), 'Exception should be raised on the "cycle" function');
        }
    }

    public function testSandboxUnallowedRangeOperator()
    {
        $twig = $this->getEnvironment(true, [], self::$templates);
        try {
            $twig->load('1_range_operator')->render(self::$params);
            $this->fail('Sandbox throws a SecurityError exception if the unallowed range operator is called');
        } catch (SecurityError $e) {
            $this->assertInstanceOf(SecurityNotAllowedFunctionError::class, $e, 'Exception should be an instance of Twig_Sandbox_SecurityNotAllowedFunctionError');
            $this->assertEquals('range', $e->getFunctionName(), 'Exception should be raised on the "range" function');
        }
    }

    public function testSandboxAllowMethodFoo()
    {
        $twig = $this->getEnvironment(true, [], self::$templates, [], [], ['Twig\Tests\Extension\FooObject' => 'foo']);
        FooObject::reset();
        $this->assertEquals('foo', $twig->load('1_basic1')->render(self::$params), 'Sandbox allow some methods');
        $this->assertEquals(1, FooObject::$called['foo'], 'Sandbox only calls method once');
    }

    public function testSandboxAllowFilter()
    {
        $twig = $this->getEnvironment(true, [], self::$templates, [], ['upper']);
        $this->assertEquals('FABIEN', $twig->load('1_basic2')->render(self::$params), 'Sandbox allow some filters');
    }

    public function testSandboxAllowTag()
    {
        $twig = $this->getEnvironment(true, [], self::$templates, ['if']);
        $this->assertEquals('foo', $twig->load('1_basic3')->render(self::$params), 'Sandbox allow some tags');
    }

    public function testSandboxAllowProperty()
    {
        $twig = $this->getEnvironment(true, [], self::$templates, [], [], [], ['Twig\Tests\Extension\FooObject' => 'bar']);
        $this->assertEquals('bar', $twig->load('1_basic4')->render(self::$params), 'Sandbox allow some properties');
    }

    public function testSandboxAllowFunction()
    {
        $twig = $this->getEnvironment(true, [], self::$templates, [], [], [], [], ['cycle']);
        $this->assertEquals('bar', $twig->load('1_basic7')->render(self::$params), 'Sandbox allow some functions');
    }

    public function testSandboxAllowRangeOperator()
    {
        $twig = $this->getEnvironment(true, [], self::$templates, [], [], [], [], ['range']);
        $this->assertEquals('1', $twig->load('1_range_operator')->render(self::$params), 'Sandbox allow the range operator');
    }

    public function testSandboxAllowFunctionsCaseInsensitive()
    {
        foreach (['getfoobar', 'getFoobar', 'getFooBar'] as $name) {
            $twig = $this->getEnvironment(true, [], self::$templates, [], [], ['Twig\Tests\Extension\FooObject' => $name]);
            FooObject::reset();
            $this->assertEquals('foobarfoobar', $twig->load('1_basic8')->render(self::$params), 'Sandbox allow methods in a case-insensitive way');
            $this->assertEquals(2, FooObject::$called['getFooBar'], 'Sandbox only calls method once');

            $this->assertEquals('foobarfoobar', $twig->load('1_basic9')->render(self::$params), 'Sandbox allow methods via shortcut names (ie. without get/set)');
        }
    }

    public function testSandboxLocallySetForAnInclude()
    {
        self::$templates = [
            '2_basic' => '{{ obj.foo }}{% include "2_included" %}{{ obj.foo }}',
            '2_included' => '{% if obj.foo %}{{ obj.foo|upper }}{% endif %}',
        ];

        $twig = $this->getEnvironment(false, [], self::$templates);
        $this->assertEquals('fooFOOfoo', $twig->load('2_basic')->render(self::$params), 'Sandbox does nothing if disabled globally and sandboxed not used for the include');

        self::$templates = [
            '3_basic' => '{{ obj.foo }}{% sandbox %}{% include "3_included" %}{% endsandbox %}{{ obj.foo }}',
            '3_included' => '{% if obj.foo %}{{ obj.foo|upper }}{% endif %}',
        ];

        $twig = $this->getEnvironment(true, [], self::$templates);
        try {
            $twig->load('3_basic')->render(self::$params);
            $this->fail('Sandbox throws a SecurityError exception when the included file is sandboxed');
        } catch (SecurityError $e) {
            $this->assertInstanceOf(SecurityNotAllowedTagError::class, $e, 'Exception should be an instance of Twig_Sandbox_SecurityNotAllowedTagError');
            $this->assertEquals('sandbox', $e->getTagName());
        }
    }

    public function testMacrosInASandbox()
    {
        $twig = $this->getEnvironment(true, ['autoescape' => 'html'], ['index' => <<<EOF
{%- import _self as macros %}

{%- macro test(text) %}<p>{{ text }}</p>{% endmacro %}

{{- macros.test('username') }}
EOF
        ], ['macro', 'import'], ['escape']);

        $this->assertEquals('<p>username</p>', $twig->load('index')->render([]));
    }

    public function testSandboxDisabledAfterIncludeFunctionError()
    {
        $twig = $this->getEnvironment(false, [], self::$templates);

        $e = null;
        try {
            $twig->load('1_include')->render(self::$params);
        } catch (\Throwable $e) {
        }
        if (null === $e) {
            $this->fail('An exception should be thrown for this test to be valid.');
        }

        $this->assertFalse($twig->getExtension(SandboxExtension::class)->isSandboxed(), 'Sandboxed include() function call should not leave Sandbox enabled when an error occurs.');
    }

    public function testSandboxTagIncludeWithPreloadedTemplate()
    {
        $twig = $this->getEnvironment(false, [], [
            'index' => '{% sandbox %}{% include "included" %}{% endsandbox %}',
            'included' => '{{ "hello"|upper }}',
        ]);

        $twig->load('included');

        $this->expectException(SecurityNotAllowedFilterError::class);
        $twig->load('index')->render([]);
    }

    public function testSandboxTagIncludeIgnoreMissingWithPreloadedTemplate()
    {
        $twig = $this->getEnvironment(false, [], [
            'index' => '{% sandbox %}{% include "included" ignore missing %}{% endsandbox %}',
            'included' => '{{ "hello"|upper }}',
        ]);

        $twig->load('included');

        $this->expectException(SecurityNotAllowedFilterError::class);
        $twig->load('index')->render([]);
    }

    public function testSandboxWithNoClosureFilter()
    {
        $this->expectException('\Twig\Error\RuntimeError');
        $this->expectExceptionMessage('The callable passed to the "filter" filter must be a Closure in sandbox mode in "index" at line 1.');

        $twig = $this->getEnvironment(true, ['autoescape' => 'html'], ['index' => <<<EOF
{{ ["foo", "bar", ""]|filter("trim")|join(", ") }}
EOF
        ], [], ['escape', 'filter', 'join']);

        $twig->load('index')->render([]);
    }

    public function testSandboxWithClosureFilter()
    {
        $twig = $this->getEnvironment(true, ['autoescape' => 'html'], ['index' => <<<EOF
{{ ["foo", "bar", ""]|filter(v => v != "")|join(", ") }}
EOF
        ], [], ['escape', 'filter', 'join']);

        $this->assertSame('foo, bar', $twig->load('index')->render([]));
    }

    public function testMultipleClassMatchesViaInheritanceInAllowedMethods()
    {
        $twig_child_first = $this->getEnvironment(true, [], self::$templates, [], [], [
            'Twig\Tests\Extension\ChildClass' => ['ChildMethod'],
            'Twig\Tests\Extension\ParentClass' => ['ParentMethod'],
        ]);
        $twig_parent_first = $this->getEnvironment(true, [], self::$templates, [], [], [
            'Twig\Tests\Extension\ParentClass' => ['ParentMethod'],
            'Twig\Tests\Extension\ChildClass' => ['ChildMethod'],
        ]);

        try {
            $twig_child_first->load('1_childobj_childmethod')->render(self::$params);
        } catch (SecurityError $e) {
            $this->fail('This test case is malfunctioning as even the child class method which comes first is not being allowed.');
        }

        try {
            $twig_parent_first->load('1_childobj_parentmethod')->render(self::$params);
        } catch (SecurityError $e) {
            $this->fail('This test case is malfunctioning as even the parent class method which comes first is not being allowed.');
        }

        try {
            $twig_parent_first->load('1_childobj_childmethod')->render(self::$params);
        } catch (SecurityError $e) {
            $this->fail('checkMethodAllowed is exiting prematurely after matching a parent class and not seeing a method allowed on a child class later in the list');
        }

        try {
            $twig_child_first->load('1_childobj_parentmethod')->render(self::$params);
        } catch (SecurityError $e) {
            $this->fail('checkMethodAllowed is exiting prematurely after matching a child class and not seeing a method allowed on its parent class later in the list');
        }
    }

    public function testSandboxAllowsColumnFilterOnAllowedProperty()
    {
        $params = ['obj' => new ColumnObject()];
        $twig = $this->getEnvironment(true, [], ['index' => "{{ [obj]|column('bar')|first }}"], [], ['column', 'first'], [], ['Twig\Tests\Extension\ColumnObject' => ['bar']]);

        $this->assertSame('bar', $twig->load('index')->render($params));
    }

    /**
     * @dataProvider getColumnFilterSandboxModes
     */
    public function testSandboxBlocksColumnFilterOnDisallowedProperty($sandboxedGlobally)
    {
        $params = ['obj' => new ColumnObject()];
        $twig = $this->getEnvironment($sandboxedGlobally, [], ['index' => "{{ [obj]|column('bar')|first }}"], [], ['column', 'first'], [], [], [], $sandboxedGlobally ? null : $this->getAlwaysSandboxedSourcePolicy());

        try {
            $twig->load('index')->render($params);
            $this->fail('Sandbox should reject the "column" filter when the requested property is not in allowedProperties');
        } catch (SecurityNotAllowedPropertyError $e) {
            $this->assertSame('Twig\Tests\Extension\ColumnObject', $e->getClassName());
            $this->assertSame('bar', $e->getPropertyName());
        }
    }

    /**
     * @dataProvider getColumnFilterSandboxModes
     */
    public function testSandboxBlocksColumnFilterOnDisallowedIndex($sandboxedGlobally)
    {
        $params = ['obj' => new ColumnObject()];
        $twig = $this->getEnvironment($sandboxedGlobally, [], ['index' => "{{ [obj]|column('bar', 'foo')|keys|first }}"], [], ['column', 'first', 'keys'], [], ['Twig\Tests\Extension\ColumnObject' => ['bar']], [], $sandboxedGlobally ? null : $this->getAlwaysSandboxedSourcePolicy());

        try {
            $twig->load('index')->render($params);
            $this->fail('Sandbox should reject the "column" filter when the index argument targets a disallowed property');
        } catch (SecurityNotAllowedPropertyError $e) {
            $this->assertSame('Twig\Tests\Extension\ColumnObject', $e->getClassName());
            $this->assertSame('foo', $e->getPropertyName());
        }
    }

    /**
     * @dataProvider getColumnFilterSandboxModes
     */
    public function testSandboxBlocksColumnFilterOnMagicGetter($sandboxedGlobally)
    {
        $params = ['magic' => new MagicObject()];
        $twig = $this->getEnvironment($sandboxedGlobally, [], ['index' => "{{ [magic]|column('anything')|first }}"], [], ['column', 'first'], [], [], [], $sandboxedGlobally ? null : $this->getAlwaysSandboxedSourcePolicy());

        try {
            $twig->load('index')->render($params);
            $this->fail('Sandbox should reject the "column" filter before invoking __get on a non-allowlisted property');
        } catch (SecurityNotAllowedPropertyError $e) {
            $this->assertSame('Twig\Tests\Extension\MagicObject', $e->getClassName());
            $this->assertSame('anything', $e->getPropertyName());
        }
    }

    public function getColumnFilterSandboxModes()
    {
        return [
            'sandboxed globally' => [true],
            'sandboxed by a source policy' => [false],
        ];
    }

    public function testSourcePolicySandboxAllowsColumnFilterOnAllowedProperty()
    {
        $params = ['obj' => new ColumnObject()];
        $twig = $this->getEnvironment(false, [], ['index' => "{{ [obj]|column('bar')|first }}"], [], ['column', 'first'], [], ['Twig\Tests\Extension\ColumnObject' => ['bar']], [], $this->getAlwaysSandboxedSourcePolicy());

        $this->assertSame('bar', $twig->load('index')->render($params));
    }

    public function testColumnFilterUnaffectedOutsideSandbox()
    {
        $params = ['obj' => new ColumnObject()];
        $twig = $this->getEnvironment(false, [], ['index' => "{{ [obj]|column('bar')|first }}"]);

        $this->assertSame('bar', $twig->load('index')->render($params));
    }

    /**
     * @dataProvider provideSourcePolicyArrowBlockedTemplates
     */
    public function testSourcePolicyBlocksNonClosureCallableInArrow($template)
    {
        $twig = $this->getEnvironment(false, [], ['1_basic' => $template], [], ['sort', 'filter', 'map', 'reduce', 'join'], [], [], [], $this->getAlwaysSandboxedSourcePolicy());

        try {
            $twig->load('1_basic')->render([]);
            $this->fail('A callable that is not a Closure must be refused when a source policy enables the sandbox');
        } catch (RuntimeError $e) {
            $this->assertStringContainsString('must be a Closure in sandbox mode', $e->getMessage());
        }
    }

    public function provideSourcePolicyArrowBlockedTemplates()
    {
        return [
            'sort' => ['{{ ["a","b"]|sort("strnatcasecmp")|join }}'],
            'filter' => ['{{ ["a","b"]|filter("is_string")|join }}'],
            'map' => ['{{ ["a","b"]|map("strtoupper")|join }}'],
            'reduce' => ['{{ [1,2]|reduce("max") }}'],
        ];
    }

    public function testSourcePolicyAllowsClosureInArrow()
    {
        $twig = $this->getEnvironment(false, [], ['1_basic' => '{{ ["b","a"]|sort((a, b) => a < b ? -1 : 1)|join(",") }}'], [], ['sort', 'join'], [], [], [], $this->getAlwaysSandboxedSourcePolicy());
        $this->assertSame('a,b', $twig->load('1_basic')->render([]));
    }

    public function testNonSandboxedSourcePolicyAllowsNonClosureCallable()
    {
        $sourcePolicy = new class() implements SourcePolicyInterface {
            public function enableSandbox(Source $source): bool
            {
                return false;
            }
        };

        $twig = $this->getEnvironment(false, [], ['1_basic' => '{{ ["b","a"]|sort("strnatcasecmp")|join(",") }}'], [], ['sort', 'join'], [], [], [], $sourcePolicy);
        $this->assertSame('a,b', $twig->load('1_basic')->render([]));
    }

    private function getAlwaysSandboxedSourcePolicy(): SourcePolicyInterface
    {
        return new class() implements SourcePolicyInterface {
            public function enableSandbox(Source $source): bool
            {
                return true;
            }
        };
    }

    protected function getEnvironment($sandboxed, $options, $templates, $tags = [], $filters = [], $methods = [], $properties = [], $functions = [], $sourcePolicy = null)
    {
        $loader = new ArrayLoader($templates);
        $twig = new Environment($loader, array_merge(['debug' => true, 'cache' => false, 'autoescape' => false], $options));
        $policy = new SecurityPolicy($tags, $filters, $methods, $properties, $functions);
        $twig->addExtension(new SandboxExtension($policy, $sandboxed, $sourcePolicy));

        return $twig;
    }

    public function testSandboxSourcePolicyEnableReturningFalse()
    {
        $twig = $this->getEnvironment(false, [], self::$templates, [], [], [], [], [], new class() implements \Twig\Sandbox\SourcePolicyInterface {
            public function enableSandbox(Source $source): bool
            {
                return '1_basic' != $source->getName();
            }
        });
        $this->assertEquals('FOO', $twig->load('1_basic')->render(self::$params));
    }

    public function testSandboxSourcePolicyEnableReturningTrue()
    {
        $twig = $this->getEnvironment(false, [], self::$templates, [], [], [], [], [], new class() implements \Twig\Sandbox\SourcePolicyInterface {
            public function enableSandbox(Source $source): bool
            {
                return '1_basic' === $source->getName();
            }
        });
        $this->expectException(SecurityError::class);
        $twig->load('1_basic')->render([]);
    }

    public function testSandboxSourcePolicyFalseDoesntOverrideOtherEnables()
    {
        $twig = $this->getEnvironment(true, [], self::$templates, [], [], [], [], [], new class() implements \Twig\Sandbox\SourcePolicyInterface {
            public function enableSandbox(Source $source): bool
            {
                return false;
            }
        });
        $this->expectException(SecurityError::class);
        $twig->load('1_basic')->render([]);
    }
}

class ParentClass
{
    public function ParentMethod()
    {
    }
}
class ChildClass extends ParentClass
{
    public function ChildMethod()
    {
    }
}

class FooObject
{
    public static $called = ['__toString' => 0, 'foo' => 0, 'getFooBar' => 0];

    public $bar = 'bar';

    public static function reset()
    {
        self::$called = ['__toString' => 0, 'foo' => 0, 'getFooBar' => 0];
    }

    public function __toString()
    {
        ++self::$called['__toString'];

        return 'foo';
    }

    public function foo()
    {
        ++self::$called['foo'];

        return 'foo';
    }

    public function getFooBar()
    {
        ++self::$called['getFooBar'];

        return 'foobar';
    }

    public function getAnotherFooObject()
    {
        return new self();
    }
}

class ArrayLikeObject extends \ArrayObject
{
    public function offsetExists($offset): bool
    {
        throw new \BadMethodCallException('Should not be called.');
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        throw new \BadMethodCallException('Should not be called.');
    }

    public function offsetSet($offset, $value): void
    {
    }

    public function offsetUnset($offset): void
    {
    }
}

class MagicObject
{
    #[\ReturnTypeWillChange]
    public function __get($name)
    {
        throw new \BadMethodCallException('Should not be called.');
    }

    public function __isset($name): bool
    {
        throw new \BadMethodCallException('Should not be called.');
    }
}

class CustomCoercingExpression extends AbstractExpression implements CoercesChildrenToStringInterface
{
    public function __construct(AbstractExpression $left, AbstractExpression $right, int $lineno)
    {
        parent::__construct(['left' => $left, 'right' => $right], [], $lineno);
    }

    public function getStringCoercedChildNames(): array
    {
        return ['left', 'right'];
    }
}

class CustomCoercingNode extends Node implements CoercesChildrenToStringInterface
{
    public function getStringCoercedChildNames(): array
    {
        return ['expr'];
    }
}

// Implements both __toString() and Traversable: a sandbox policy may legitimately
// allow the container's own `__toString`, but the elements yielded by
// `getIterator()` must still be policy-checked when consumers (`join`, `replace`,
// ...) materialise the iterable and coerce its contents to string.
class StringableTraversableObject implements \IteratorAggregate
{
    private $items;

    public function __construct(array $items)
    {
        $this->items = $items;
    }

    public function __toString(): string
    {
        return 'stringable-traversable';
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->items);
    }
}

// Self-referencing IteratorAggregate: getIterator() yields `$this`.
class CyclicTraversableObject implements \IteratorAggregate
{
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator([$this]);
    }
}

// Plain object without __toString: the column tests exercise property access, not
// string coercion, so the array elements must not have __toString().
class ColumnObject
{
    public $bar = 'bar';
}
