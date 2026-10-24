<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\Node\Expression;

use Twig\Compiler;
use Twig\Node\CoercesChildrenToStringInterface;

class ArrayExpression extends AbstractExpression implements CoercesChildrenToStringInterface
{
    private $index;

    public function __construct(array $elements, int $lineno)
    {
        parent::__construct($elements, [], $lineno);

        $this->index = -1;
        foreach ($this->getKeyValuePairs() as $pair) {
            if ($pair['key'] instanceof ConstantExpression && ctype_digit((string) $pair['key']->getAttribute('value')) && $pair['key']->getAttribute('value') > $this->index) {
                $this->index = $pair['key']->getAttribute('value');
            }
        }
    }

    public function getKeyValuePairs()
    {
        $pairs = [];

        foreach (array_chunk($this->nodes, 2) as $pair) {
            $pairs[] = [
                'key' => $pair[0],
                'value' => $pair[1],
            ];
        }

        return $pairs;
    }

    public function hasElement(AbstractExpression $key)
    {
        foreach ($this->getKeyValuePairs() as $pair) {
            // we compare the string representation of the keys
            // to avoid comparing the line numbers which are not relevant here.
            if ((string) $key === (string) $pair['key']) {
                return true;
            }
        }

        return false;
    }

    public function addElement(AbstractExpression $value, ?AbstractExpression $key = null)
    {
        if (null === $key) {
            $key = new ConstantExpression(++$this->index, $value->getTemplateLine());
        }

        array_push($this->nodes, $key, $value);
    }

    public function getStringCoercedChildNames(): array
    {
        // dynamic mapping keys (computed at runtime) are used as PHP array offsets;
        // static keys (constants or sequence indexes) are emitted as PHP literals
        $names = [];
        $keys = array_keys($this->nodes);
        for ($i = 0, $n = \count($keys); $i < $n; $i += 2) {
            if (!$this->nodes[$keys[$i]] instanceof ConstantExpression) {
                $names[] = (string) $keys[$i];
            }
        }

        return $names;
    }

    public function compile(Compiler $compiler)
    {
        $compiler->raw('[');
        $first = true;
        foreach ($this->getKeyValuePairs() as $pair) {
            if (!$first) {
                $compiler->raw(', ');
            }
            $first = false;

            $compiler
                ->subcompile($pair['key'])
                ->raw(' => ')
                ->subcompile($pair['value'])
            ;
        }
        $compiler->raw(']');
    }
}

class_alias('Twig\Node\Expression\ArrayExpression', 'Twig_Node_Expression_Array');
