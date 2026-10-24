<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\NodeVisitor;

use Twig\Environment;
use Twig\Node\CheckSecurityCallNode;
use Twig\Node\CheckSecurityNode;
use Twig\Node\CheckToStringNode;
use Twig\Node\CoercesChildrenToStringInterface;
use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\Binary\RangeBinary;
use Twig\Node\Expression\ConditionalExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\Expression\NameExpression;
use Twig\Node\ModuleNode;
use Twig\Node\Node;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
final class SandboxNodeVisitor extends AbstractNodeVisitor
{
    private $inAModule = false;
    private $tags;
    private $filters;
    private $functions;

    protected function doEnterNode(Node $node, Environment $env)
    {
        if ($node instanceof ModuleNode) {
            $this->inAModule = true;
            $this->tags = [];
            $this->filters = [];
            $this->functions = [];
        } elseif ($this->inAModule) {
            // look for tags
            if ($node->getNodeTag() && !isset($this->tags[$node->getNodeTag()])) {
                $this->tags[$node->getNodeTag()] = $node;
            }

            // look for filters
            if ($node instanceof FilterExpression && !isset($this->filters[$node->getNode('filter')->getAttribute('value')])) {
                $this->filters[$node->getNode('filter')->getAttribute('value')] = $node;
            }

            // look for functions
            if ($node instanceof FunctionExpression && !isset($this->functions[$node->getAttribute('name')])) {
                $this->functions[$node->getAttribute('name')] = $node;
            }

            // the .. operator is equivalent to the range() function
            if ($node instanceof RangeBinary && !isset($this->functions['range'])) {
                $this->functions['range'] = $node;
            }
        }

        // wrap children that the node itself will string-coerce at runtime;
        // applies to ModuleNode (`parent` slot for {% extends %}) too
        if ($this->inAModule && $node instanceof CoercesChildrenToStringInterface) {
            foreach ($node->getStringCoercedChildNames() as $childName) {
                $this->wrapNode($node, $childName);
            }
        }

        return $node;
    }

    protected function doLeaveNode(Node $node, Environment $env)
    {
        if ($node instanceof ModuleNode) {
            $this->inAModule = false;

            $node->setNode('constructor_end', new Node([new CheckSecurityCallNode(), $node->getNode('constructor_end')]));
            $node->setNode('class_end', new Node([new CheckSecurityNode($this->filters, $this->tags, $this->functions), $node->getNode('class_end')]));
        }

        return $node;
    }

    private function wrapNode(Node $node, string $name)
    {
        $expr = $node->getNode($name);
        // `_self` is internal: it compiles to `$this->getTemplateName()` and is always a string
        if ($expr instanceof NameExpression && '_self' === $expr->getAttribute('name')) {
            return;
        }
        if ($expr instanceof NameExpression || $expr instanceof GetAttrExpression) {
            $node->setNode($name, new CheckToStringNode($expr));
        } elseif ($expr instanceof ArrayExpression || Node::class === \get_class($expr)) {
            foreach ($expr as $name => $_) {
                $this->wrapNode($expr, $name);
            }
        } elseif ($expr instanceof ConditionalExpression) {
            // the value of a ternary, an elvis or a null-coalescing expression is one of its operands
            $this->wrapNode($expr, 'expr2');
            $this->wrapNode($expr, 'expr3');
        } elseif ($expr instanceof FilterExpression || $expr instanceof FunctionExpression) {
            // an allowed filter or function may return an object whose __toString() is not allowed
            $node->setNode($name, new CheckToStringNode($expr));
        }
    }

    public function getPriority()
    {
        return 0;
    }
}

class_alias('Twig\NodeVisitor\SandboxNodeVisitor', 'Twig_NodeVisitor_Sandbox');
