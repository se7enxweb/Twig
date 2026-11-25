<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\TokenParser;

use Twig\Node\EmbedNode;
use Twig\Token;

/**
 * Embeds a template.
 */
final class EmbedTokenParser extends IncludeTokenParser
{
    public function parse(Token $token)
    {
        $stream = $this->parser->getStream();

        $parent = $this->parser->getExpressionParser()->parseExpression();

        list($variables, $only, $ignoreMissing) = $this->parseArguments();

        // inject a fake parent to make the parent() function work
        $stream->injectTokens([
            new Token(/* Token::BLOCK_START_TYPE */ 1, '', $token->getLine()),
            new Token(/* Token::NAME_TYPE */ 5, 'extends', $token->getLine()),
            new Token(/* Token::STRING_TYPE */ 7, '__parent__', $token->getLine()),
            new Token(/* Token::BLOCK_END_TYPE */ 3, '', $token->getLine()),
        ]);

        $module = $this->parser->parseEmbeddedTemplate($stream, $parent, [$this, 'decideBlockEnd']);

        $this->parser->embedTemplate($module);

        $stream->expect(/* Token::BLOCK_END_TYPE */ 3);

        return new EmbedNode($module->getTemplateName(), $module->getAttribute('index'), $variables, $only, $ignoreMissing, $token->getLine(), $this->getTag());
    }

    public function decideBlockEnd(Token $token)
    {
        return $token->test('endembed');
    }

    public function getTag()
    {
        return 'embed';
    }
}

class_alias('Twig\TokenParser\EmbedTokenParser', 'Twig_TokenParser_Embed');
