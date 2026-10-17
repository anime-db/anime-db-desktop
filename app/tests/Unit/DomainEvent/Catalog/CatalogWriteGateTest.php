<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
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
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace App\Tests\Unit\DomainEvent\Catalog;

use PHPUnit\Framework\TestCase;

/**
 * A DBAL write into a table of the Anime aggregate (or a DQL write into its entities) bypasses the UnitOfWork, so the catalog events
 * (see AnimeChangeCollector) are not raised for it. Under app/src/ such a write is allowed only in
 * the classes below: the first three record the event explicitly through AnimeEventRecorder, the
 * rest write technical fields or serve the migration check and raise none. Catalog records are
 * deleted only through EntityManager::remove(). app/migrations/ and app/tests/ are out of scope.
 */
final class CatalogWriteGateTest extends TestCase
{
    /** The tables of the aggregate, listed explicitly: anime_sync_state and anime_plugin_data are not part of it. */
    private const AGGREGATE_TABLES = [
        'anime',
        'anime_name',
        'anime_genres',
        'anime_themes',
        'anime_description',
        'anime_source',
        'anime_image',
        'anime_external_id',
        'anime_studios',
        'anime_labels',
    ];

    /** The entities of the aggregate (DQL writes name them, not tables), with every Anime subclass. */
    private const AGGREGATE_ENTITIES = [
        'Anime',
        'MovieAnime',
        'SeriesAnime',
        'TvAnime',
        'OvaAnime',
        'OnaAnime',
        'SpecialAnime',
        'MusicAnime',
        'AnimeName',
        'AnimeGenre',
        'AnimeTheme',
        'AnimeDescription',
        'AnimeSource',
        'AnimeImage',
        'AnimeExternalId',
    ];

    /** Paths relative to app/src/. */
    private const ALLOWED = [
        'Service/Plugin/Filler/PluginDescriptionWriter.php',
        'Service/AnimeTypeChangeService.php',
        'Service/Plugin/Filler/BulkFillerService.php',
        'Service/Migration/MigrationDataIntegrityChecker.php',
        'MessageHandler/RecomputeSourceUrlsMessageHandler.php',
        'Command/FixtureLoadCommand.php',
    ];

    public function testNoClassOutsideTheAllowListWritesIntoTheAggregateTables(): void
    {
        $this->assertSame([], $this->violations(\dirname(__DIR__, 4).'/src', self::ALLOWED));
    }

    public function testEveryAllowedClassStillWritesIntoTheAggregate(): void
    {
        $root = \dirname(__DIR__, 4).'/src';
        $writers = array_keys($this->violations($root, []));
        sort($writers);
        $allowed = self::ALLOWED;
        sort($allowed);

        $this->assertSame($allowed, $writers, 'Remove a class from the allow list once it stops writing into the aggregate.');
    }

    public function testEveryAnimeSubclassIsCoveredByTheEntityList(): void
    {
        $found = [];
        foreach (glob(\dirname(__DIR__, 4).'/src/Entity/*.php') ?: [] as $file) {
            $class = 'App\\Entity\\'.basename($file, '.php');
            if (class_exists($class) && is_a($class, \App\Entity\Anime::class, true)) {
                $found[] = basename($file, '.php');
            }
        }

        $this->assertSame([], array_diff($found, self::AGGREGATE_ENTITIES), 'Add the new Anime subclass to AGGREGATE_ENTITIES.');
    }

    /** @return iterable<string, array{string}> */
    public static function forbiddenWrites(): iterable
    {
        yield 'executeStatement update' => ['$this->connection->executeStatement(\'UPDATE anime SET title = ? WHERE id = ?\', [$a, $b]);'];
        yield 'lowercase delete' => ['$connection->executeStatement("delete from anime_labels where anime_id = 1");'];
        yield 'insert or replace' => ['$connection->executeStatement(\'INSERT OR REPLACE INTO anime_name (anime_id, name) VALUES (1, 2)\');'];
        yield 'quoted table' => ['$connection->executeStatement(\'UPDATE "anime_image" SET source = 1\');'];
        yield 'Connection::insert' => ['$connection->insert(\'anime_genres\', [\'anime_id\' => 1]);'];
        yield 'Connection::update' => ['$connection->update("anime", [\'title\' => \'x\'], [\'id\' => 1]);'];
        yield 'Connection::delete' => ['$connection->delete(\'anime_external_id\', [\'anime_id\' => 1]);'];
        yield 'DQL update, single quotes' => ['$em->createQuery(\'UPDATE App\\Entity\\Anime a SET a.title = 1\')->execute();'];
        yield 'DQL update, escaped backslashes' => ['$em->createQuery("UPDATE App\\\\Entity\\\\AnimeName n SET n.name = 1")->execute();'];
        yield 'DQL update of a subclass' => ['$em->createQuery(\'update App\\Entity\\TvAnime a SET a.title = 1\')->execute();'];
        yield 'DQL delete' => ['$em->createQuery(\'DELETE App\\Entity\\AnimeGenre g\')->execute();'];
        yield 'DQL delete from' => ['$em->createQuery(\'DELETE FROM App\\Entity\\AnimeTheme t WHERE t.id = 1\')->execute();'];
        yield 'DQL leading backslash' => ['$em->createQuery(\'UPDATE \\App\\Entity\\AnimeDescription d SET d.description = 1\')->execute();'];
        yield 'DQL with a class constant' => ['$em->createQuery(\'UPDATE \'.AnimeSource::class.\' s SET s.url = 1\')->execute();'];
        yield 'DQL with a qualified class constant' => ['$em->createQuery(\'DELETE \'.\\App\\Entity\\AnimeImage::class)->execute();'];
        yield 'QueryBuilder update' => ['$em->createQueryBuilder()->update(Anime::class, \'a\')->set(\'a.title\', 1)->getQuery()->execute();'];
        yield 'QueryBuilder delete' => ['$em->createQueryBuilder()->delete(AnimeExternalId::class, \'e\')->getQuery()->execute();'];
        yield 'QueryBuilder update of a subclass' => ['$qb->update(\\App\\Entity\\MovieAnime::class, \'a\');'];
        yield 'QueryBuilder with a literal class name' => ['$qb->delete(\'App\\Entity\\AnimeName\', \'n\');'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('forbiddenWrites')]
    public function testTheGateCatchesAWriteFromAnUnlistedClass(string $statement): void
    {
        $dir = $this->scratchDir(['Rogue.php' => "<?php\nfinal class Rogue { public function run(): void { {$statement} } }\n"]);

        $this->assertSame(['Rogue.php'], array_keys($this->violations($dir, [])));
        $this->assertSame([], $this->violations($dir, ['Rogue.php']));
    }

    /** @return iterable<string, array{string}> */
    public static function permittedCode(): iterable
    {
        yield 'table outside the aggregate' => ['$connection->executeStatement(\'UPDATE anime_sync_state SET last_status = 1\');'];
        yield 'plugin data' => ['$connection->insert(\'anime_plugin_data\', [\'anime_id\' => 1]);'];
        yield 'select' => ['$connection->fetchOne(\'SELECT id FROM anime WHERE id = 1\');'];
        yield 'DQL select' => ['$em->createQuery(\'SELECT a FROM App\\Entity\\Anime a\')->getResult();'];
        yield 'DQL update outside the aggregate' => ['$em->createQuery(\'UPDATE App\\Entity\\AnimeSyncState s SET s.x = 1\')->execute();'];
        yield 'QueryBuilder update outside the aggregate' => ['$qb->update(Notification::class, \'n\');'];
        yield 'QueryBuilder select' => ['$qb->select(\'a\')->from(Anime::class, \'a\');'];
        yield 'comment' => ['// UPDATE anime SET title = 1'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('permittedCode')]
    public function testTheGateIgnoresWhatDoesNotWriteIntoTheAggregate(string $statement): void
    {
        $dir = $this->scratchDir(['Fine.php' => "<?php\nfinal class Fine { public function run(): void { {$statement} } }\n"]);

        $this->assertSame([], $this->violations($dir, []));
    }

    /**
     * @param list<string> $allowed
     *
     * @return array<string, list<string>> path relative to $root => the offending statements
     */
    private function violations(string $root, array $allowed): array
    {
        $tables = implode('|', self::AGGREGATE_TABLES);
        $entities = implode('|', self::AGGREGATE_ENTITIES);
        $patterns = [
            '/\b(?:INSERT\s+(?:OR\s+\w+\s+)?INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM)\s+["`\[]?(?:'.$tables.')(?![\w])/i',
            '/->\s*(?:insert|update|delete)\(\s*[\'"](?:'.$tables.')[\'"]/',
            // DQL in a string: the entity is named by its FQCN (any number of backslashes, escaped or not).
            '/\b(?:UPDATE|DELETE(?:\s+FROM)?)\s+\\\\*App\\\\+Entity\\\\+(?:'.$entities.')(?![\w])/i',
            // DQL assembled with a class constant, and QueryBuilder::update()/delete() with an entity.
            '/\b(?:UPDATE|DELETE(?:\s+FROM)?)\s*[\'"]\s*\.\s*[\w\\\\]*?(?<![\w])(?:'.$entities.')::class/i',
            '/->\s*(?:update|delete)\(\s*(?:\\\\?(?:\w+\\\\)*(?:'.$entities.')::class|[\'"]\\\\*App\\\\+Entity\\\\+(?:'.$entities.')[\'"])/',
        ];

        $violations = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), \strlen($root) + 1);
            if (\in_array($relative, $allowed, true)) {
                continue;
            }

            $code = '';
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (!\is_array($token)) {
                    $code .= $token;
                } elseif (!\in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                    $code .= $token[1];
                }
            }

            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $code, $matches) > 0) {
                    $violations[$relative] = [...($violations[$relative] ?? []), ...$matches[0]];
                }
            }
        }

        return $violations;
    }

    /** @param array<string, string> $files */
    private function scratchDir(array $files): string
    {
        $dir = sys_get_temp_dir().'/catalog-write-gate-'.bin2hex(random_bytes(6));
        mkdir($dir);
        foreach ($files as $name => $contents) {
            file_put_contents($dir.'/'.$name, $contents);
        }
        $this->scratch[] = $dir;

        return $dir;
    }

    /** @var list<string> */
    private array $scratch = [];

    protected function tearDown(): void
    {
        foreach ($this->scratch as $dir) {
            array_map('unlink', glob($dir.'/*') ?: []);
            rmdir($dir);
        }
    }
}
