<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
 */

/*
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Doctrine\Query;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * DQL "REPLACE(string, search, replace)", mapped 1:1 to the SQL REPLACE() function. Not
 * part of Doctrine's built-in DQL string functions (unlike LOWER/TRIM), even though every
 * platform this app targets (SQLite, and any future MySQL/Postgres backend) supports it
 * natively. Registered as "REPLACE" under orm.dql.string_functions in doctrine.yaml.
 */
final class ReplaceFunction extends FunctionNode
{
    private Node $stringExpression;
    private Node $searchExpression;
    private Node $replaceExpression;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->stringExpression = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->searchExpression = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->replaceExpression = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return sprintf(
            'REPLACE(%s, %s, %s)',
            $sqlWalker->walkStringPrimary($this->stringExpression),
            $sqlWalker->walkStringPrimary($this->searchExpression),
            $sqlWalker->walkStringPrimary($this->replaceExpression),
        );
    }
}
