<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\Extension;

use Twig\NodeVisitor\SandboxNodeVisitor;
use Twig\Sandbox\SecurityNotAllowedMethodError;
use Twig\Sandbox\SecurityNotAllowedPropertyError;
use Twig\Sandbox\SecurityPolicyInterface;
use Twig\Sandbox\SourcePolicyInterface;
use Twig\Source;
use Twig\TokenParser\SandboxTokenParser;

final class SandboxExtension extends AbstractExtension
{
    private $sandboxedGlobally;
    private $sandboxed;
    private $policy;
    private $sourcePolicy;

    public function __construct(SecurityPolicyInterface $policy, $sandboxed = false, ?SourcePolicyInterface $sourcePolicy = null)
    {
        $this->policy = $policy;
        $this->sandboxedGlobally = $sandboxed;
        $this->sourcePolicy = $sourcePolicy;
    }

    public function getTokenParsers()
    {
        return [new SandboxTokenParser()];
    }

    public function getNodeVisitors()
    {
        return [new SandboxNodeVisitor()];
    }

    public function enableSandbox()
    {
        $this->sandboxed = true;
    }

    public function disableSandbox()
    {
        $this->sandboxed = false;
    }

    public function isSandboxed(?Source $source = null)
    {
        return $this->sandboxedGlobally || $this->sandboxed || $this->isSourceSandboxed($source);
    }

    public function isSandboxedGlobally()
    {
        return $this->sandboxedGlobally;
    }

    /**
     * @internal
     */
    public function hasSourcePolicy(): bool
    {
        return null !== $this->sourcePolicy;
    }

    private function isSourceSandboxed(?Source $source): bool
    {
        if (null === $source || null === $this->sourcePolicy) {
            return false;
        }

        return $this->sourcePolicy->enableSandbox($source);
    }

    public function setSecurityPolicy(SecurityPolicyInterface $policy)
    {
        $this->policy = $policy;
    }

    public function getSecurityPolicy()
    {
        return $this->policy;
    }

    public function checkSecurity($tags, $filters, $functions, ?Source $source = null)
    {
        if ($this->isSandboxed($source)) {
            $this->policy->checkSecurity($tags, $filters, $functions);
        }
    }

    public function checkMethodAllowed($obj, $method, int $lineno = -1, ?Source $source = null)
    {
        if ($this->isSandboxed($source)) {
            try {
                $this->policy->checkMethodAllowed($obj, $method);
            } catch (SecurityNotAllowedMethodError $e) {
                $e->setSourceContext($source);
                $e->setTemplateLine($lineno);

                throw $e;
            }
        }
    }

    public function checkPropertyAllowed($obj, $property, int $lineno = -1, ?Source $source = null)
    {
        if ($this->isSandboxed($source)) {
            try {
                $this->policy->checkPropertyAllowed($obj, $property);
            } catch (SecurityNotAllowedPropertyError $e) {
                $e->setSourceContext($source);
                $e->setTemplateLine($lineno);

                throw $e;
            }
        }
    }

    /**
     * @throws SecurityNotAllowedMethodError
     */
    public function ensureToStringAllowed($obj, int $lineno = -1, ?Source $source = null)
    {
        return $this->doEnsureToStringAllowed($obj, $lineno, $source, new \SplObjectStorage());
    }

    private function doEnsureToStringAllowed($obj, int $lineno, ?Source $source, \SplObjectStorage $seen)
    {
        if (\is_array($obj)) {
            $this->ensureToStringAllowedForArray($obj, $lineno, $source, $seen);

            return $obj;
        }

        if (!$this->isSandboxed($source) || !\is_object($obj)) {
            return $obj;
        }

        $stringable = method_exists($obj, '__toString');
        if ($stringable) {
            try {
                $this->policy->checkMethodAllowed($obj, '__toString');
            } catch (SecurityNotAllowedMethodError $e) {
                $e->setSourceContext($source);
                $e->setTemplateLine($lineno);

                throw $e;
            }
        }

        // Elements yielded by a Traversable may be string-coerced downstream
        // (e.g. by `join`/`replace` or the `in` operator), bypassing the policy. Check them now.
        if ($obj instanceof \Traversable) {
            // guard against self-referencing iterables (an IteratorAggregate yielding itself)
            if (isset($seen[$obj])) {
                return $obj;
            }
            $seen[$obj] = true;

            // IteratorAggregate::getIterator() is idempotent, so we can walk
            // the elements and return the original object: host code typed
            // against a specific class (e.g. FormView) keeps working.
            if ($obj instanceof \IteratorAggregate) {
                foreach ($obj as $v) {
                    $this->doEnsureToStringAllowed($v, $lineno, $source, $seen);
                }

                return $obj;
            }

            // Single-pass Iterator/Generator: materialise to validate.
            $array = iterator_to_array($obj);
            $this->ensureToStringAllowedForArray($array, $lineno, $source, $seen);

            if (!$stringable) {
                return $array;
            }
        }

        return $obj;
    }

    private function ensureToStringAllowedForArray(array $obj, int $lineno, ?Source $source, \SplObjectStorage $seen, array &$stack = []): void
    {
        foreach ($obj as $k => $v) {
            if (!$v) {
                continue;
            }

            if (!\is_array($v)) {
                $this->doEnsureToStringAllowed($v, $lineno, $source, $seen);
                continue;
            }

            if (\PHP_VERSION_ID < 70400) {
                static $cookie;

                if ($v === $cookie ?? $cookie = new \stdClass()) {
                    continue;
                }

                $obj[$k] = $cookie;
                try {
                    $this->ensureToStringAllowedForArray($v, $lineno, $source, $seen, $stack);
                } finally {
                    $obj[$k] = $v;
                }

                continue;
            }

            if ($r = \ReflectionReference::fromArrayElement($obj, $k)) {
                if (isset($stack[$r->getId()])) {
                    continue;
                }

                $stack[$r->getId()] = true;
            }

            $this->ensureToStringAllowedForArray($v, $lineno, $source, $seen, $stack);
        }
    }
}

class_alias('Twig\Extension\SandboxExtension', 'Twig_Extension_Sandbox');
