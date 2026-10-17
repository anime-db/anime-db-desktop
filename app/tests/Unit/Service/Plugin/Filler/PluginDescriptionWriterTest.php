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

namespace App\Tests\Unit\Service\Plugin\Filler;

use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Anime;
use App\Entity\AnimeDescription;
use App\Entity\Enum\WatchStatus;
use App\Entity\TvAnime;
use App\Entity\ValueObject\PluginId;
use App\Service\AppConfigStore;
use App\Service\AppSettingsProvider;
use App\Service\Plugin\Filler\PluginDescriptionWriter;
use App\Tests\Support\CatalogEventProbe;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PluginDescriptionWriterTest extends TestCase
{
    private EntityManager $entityManager;
    private PluginDescriptionWriter $writer;

    private string $configPath;

    protected function tearDown(): void
    {
        foreach ([$this->configPath, $this->configPath.'.lock', $this->configPath.'.tmp'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /** @param ?list<string> $priority null = never saved */
    private function writerWithPriority(?array $priority): PluginDescriptionWriter
    {
        $settings = new AppSettingsProvider(new AppConfigStore($this->configPath));
        if ($priority !== null) {
            $settings->setDescriptionSourcePriority($priority);
        }

        return new PluginDescriptionWriter($this->entityManager, $settings);
    }

    protected function setUp(): void
    {
        $this->configPath = sys_get_temp_dir().'/description-writer-'.bin2hex(random_bytes(6)).'.json';
        if (!Type::hasType(UnixTimestampType::NAME)) {
            Type::addType(UnixTimestampType::NAME, UnixTimestampType::class);
        }
        if (!Type::hasType(RatingType::NAME)) {
            Type::addType(RatingType::NAME, RatingType::class);
        }

        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 5).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->entityManager = new EntityManager($connection, $config);
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->writer = new PluginDescriptionWriter($this->entityManager);
    }

    /** @return array<string, array{0: string, 1: ?string}> raw rows, bypassing the identity map: locale => [description, source] */
    private function rows(Anime $anime): array
    {
        $rows = [];
        foreach ($this->entityManager->getConnection()->fetchAllAssociative('SELECT locale, description, source FROM anime_description WHERE anime_id = ?', [$anime->id]) as $row) {
            $rows[$row['locale']] = [$row['description'], $row['source']];
        }

        return $rows;
    }

    private function animeWith(?string $text, ?string $source, string $locale = 'ru'): Anime
    {
        $anime = new TvAnime();
        $anime->setTitle('Bleach')->setWatchStatus(WatchStatus::Plan);
        if ($text !== null) {
            $anime->setDescription($locale, $text, $source);
        }
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    public function testMissingLocaleIsInsertedWithPluginSource(): void
    {
        $anime = $this->animeWith(null, null);

        $this->writer->write($anime, new PluginId('plugin-p'), 'ru', 'Text');

        $this->assertSame(['ru' => ['Text', 'plugin-p']], $this->rows($anime));
        $this->assertSame('Text', $anime->getSummary('ru'));
        $this->entityManager->flush();
        $this->assertSame(['ru' => ['Text', 'plugin-p']], $this->rows($anime));
    }

    public function testInsertAndFillBumpDateUpdateOnFlush(): void
    {
        foreach ([null, ''] as $text) {
            $anime = $this->animeWith($text, null);
            $this->entityManager->clear();
            $anime = $this->entityManager->find(Anime::class, $anime->id);
            $this->assertNotNull($anime);
            $before = new \DateTimeImmutable('2020-01-01');
            (new \ReflectionProperty(Anime::class, 'dateUpdate'))->setValue($anime, $before);
            $this->entityManager->getUnitOfWork()->setOriginalEntityProperty(spl_object_id($anime), 'dateUpdate', $before);

            $this->writer->write($anime, new PluginId('plugin-p'), 'ru', 'Text');
            $this->entityManager->flush();

            $this->assertGreaterThan($before, $anime->getDateUpdate());
        }
    }

    public function testPluginTextIsStoredNormalized(): void
    {
        $anime = $this->animeWith(null, null);

        $this->writer->write($anime, new PluginId('plugin-p'), 'ru', "  Line one\r\nLine two\r  ");

        $this->assertSame(['ru' => ["Line one\nLine two", 'plugin-p']], $this->rows($anime));
    }

    public function testEmptyTextWithUnknownSourceIsFilled(): void
    {
        $anime = $this->animeWith('', null);

        $this->writer->write($anime, new PluginId('plugin-p'), 'ru', 'Text');

        $this->assertSame(['ru' => ['Text', 'plugin-p']], $this->rows($anime));
    }

    public function testManualRowIsNeverTouched(): void
    {
        $anime = $this->animeWith('Mine', 'manual');

        $this->writer->write($anime, new PluginId('plugin-p'), 'ru', 'Text');

        $this->assertSame(['ru' => ['Mine', 'manual']], $this->rows($anime));
    }

    public function testManualRowWithEmptyTextIsNeverTouched(): void
    {
        $anime = $this->animeWith('', 'manual');

        $this->writer->write($anime, new PluginId('plugin-p'), 'ru', 'Text');

        $this->assertSame(['ru' => ['', 'manual']], $this->rows($anime));
    }

    public function testUnknownSourceWithEqualNormalizedTextIsOnlyAttributed(): void
    {
        $stored = "  Line one\r\nLine two \r\n";
        $anime = $this->animeWith($stored, null);

        $this->writer->write($anime, new PluginId('plugin-p'), 'ru', "Line one\nLine two");

        $this->assertSame(['ru' => [$stored, 'plugin-p']], $this->rows($anime));
        $this->entityManager->flush();
        $this->assertSame(['ru' => [$stored, 'plugin-p']], $this->rows($anime));
    }

    public function testUnknownSourceWithDifferentTextIsNotTouched(): void
    {
        $anime = $this->animeWith('Hand-written', null);

        $this->writer->write($anime, new PluginId('plugin-p'), 'ru', 'Text');

        $this->assertSame(['ru' => ['Hand-written', null]], $this->rows($anime));
    }

    public function testRowOfAnotherPluginIsNotTouched(): void
    {
        $anime = $this->animeWith('Other text', 'plugin-q');

        $this->writer->write($anime, new PluginId('plugin-p'), 'ru', 'Text');

        $this->assertSame(['ru' => ['Other text', 'plugin-q']], $this->rows($anime));
    }

    public function testRowOfTheSamePluginWithNewTextIsNotTouched(): void
    {
        $anime = $this->animeWith('Old text', 'plugin-p');

        $this->writer->write($anime, new PluginId('plugin-p'), 'ru', 'New text');

        $this->assertSame(['ru' => ['Old text', 'plugin-p']], $this->rows($anime));
    }

    public function testWriteIsSkippedWhenTheRowBecameManualAfterTheSnapshot(): void
    {
        $anime = $this->animeWith('', null);
        // The user saves their own text after the entity was loaded and before the plugin write.
        $this->entityManager->getConnection()->executeStatement("UPDATE anime_description SET description = 'Mine', source = 'manual' WHERE anime_id = ?", [$anime->id]);

        $this->writer->write($anime, new PluginId('plugin-p'), 'ru', 'Text');

        $this->assertSame(['ru' => ['Mine', 'manual']], $this->rows($anime));
    }

    public function testAttributionIsSkippedWhenTheTextChangedAfterTheSnapshot(): void
    {
        $anime = $this->animeWith('Text', null);
        $this->entityManager->getConnection()->executeStatement("UPDATE anime_description SET description = 'Edited' WHERE anime_id = ?", [$anime->id]);

        $this->writer->write($anime, new PluginId('plugin-p'), 'ru', 'Text');

        $this->assertSame(['ru' => ['Edited', null]], $this->rows($anime));
    }

    public function testInsertLostToAConcurrentWriterIsSkippedAndKeepsTheEntityManagerOpen(): void
    {
        $anime = $this->animeWith(null, null);
        $this->assertCount(0, $anime->getDescriptions());
        // Another writer inserts the same locale after the snapshot (empty collection) was taken.
        $this->entityManager->getConnection()->executeStatement("INSERT INTO anime_description (anime_id, locale, description, source) VALUES (?, 'ru', 'Mine', 'manual')", [$anime->id]);

        $this->writer->write($anime, new PluginId('plugin-p'), 'ru', 'Text');

        $this->assertTrue($this->entityManager->isOpen());
        $this->assertSame(['ru' => ['Mine', 'manual']], $this->rows($anime));
        $this->entityManager->flush();
        $this->assertTrue($this->entityManager->isOpen());
    }

    public function testUnpersistedAnimeIsWrittenThroughTheEntity(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Bleach')->setWatchStatus(WatchStatus::Plan);

        $this->writer->write($anime, new PluginId('plugin-p'), 'ru', "A\r\nB");
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $this->assertSame(['ru' => ["A\nB", 'plugin-p']], $this->rows($anime));
        $this->assertInstanceOf(AnimeDescription::class, $anime->getDescriptions()->first());
    }

    /** @return iterable<string, array{0: ?list<string>, 1: ?string, 2: ?string, 3: string, 4: string, 5: ?string}> */
    public static function priorityMatrix(): iterable
    {
        // priority, row source, row text(null = NULL source case uses 'Old'), plugin, expected text, expected source
        yield 'never saved, same source' => [null, 'plugin-p', 'Old', 'plugin-p', 'Old', 'plugin-p'];
        yield 'saved empty, same source' => [[], 'plugin-p', 'Old', 'plugin-p', 'Old', 'plugin-p'];
        yield 'never saved, other source' => [null, 'plugin-q', 'Old', 'plugin-p', 'Old', 'plugin-q'];
        yield 'configured, same source updates' => [['plugin-p'], 'plugin-p', 'Old', 'plugin-p', 'New', 'plugin-p'];
        yield 'configured, higher plugin replaces' => [['plugin-p', 'plugin-q'], 'plugin-q', 'Old', 'plugin-p', 'New', 'plugin-p'];
        yield 'configured, lower plugin does not' => [['plugin-p', 'plugin-q'], 'plugin-p', 'Old', 'plugin-q', 'Old', 'plugin-p'];
        yield 'ranked replaces unranked' => [['plugin-p'], 'plugin-u', 'Old', 'plugin-p', 'New', 'plugin-p'];
        yield 'unranked does not replace ranked' => [['plugin-p'], 'plugin-p', 'Old', 'plugin-u', 'Old', 'plugin-p'];
        yield 'unranked does not replace unranked' => [['plugin-p'], 'plugin-u1', 'Old', 'plugin-u2', 'Old', 'plugin-u1'];
        yield 'manual is protected' => [['plugin-p'], 'manual', 'Old', 'plugin-p', 'Old', 'manual'];
        yield 'manual empty is protected' => [['plugin-p'], 'manual', '', 'plugin-p', '', 'manual'];
        yield 'NULL with other text is protected' => [['plugin-p'], null, 'Old', 'plugin-p', 'Old', null];
    }

    /** @param ?list<string> $priority */
    #[DataProvider('priorityMatrix')]
    public function testSourcePriorityRules(?array $priority, ?string $rowSource, string $rowText, string $plugin, string $expectedText, ?string $expectedSource): void
    {
        $anime = $this->animeWith($rowText, $rowSource);

        $this->writerWithPriority($priority)->write($anime, new PluginId($plugin), 'ru', "New\r\n");

        $this->assertSame(['ru' => [$expectedText, $expectedSource]], $this->rows($anime));
    }

    public function testUpdatedTextIsStoredNormalized(): void
    {
        $anime = $this->animeWith('Old', 'plugin-p');

        $this->writerWithPriority(['plugin-p'])->write($anime, new PluginId('plugin-p'), 'ru', "  A\r\nB  ");

        $this->assertSame(['ru' => ["A\nB", 'plugin-p']], $this->rows($anime));
    }

    public function testNullRowWithEqualTextIsAttributedWithoutPriority(): void
    {
        $anime = $this->animeWith('Same', null);

        $this->writerWithPriority(null)->write($anime, new PluginId('plugin-p'), 'ru', 'Same');

        $this->assertSame(['ru' => ['Same', 'plugin-p']], $this->rows($anime));
    }

    public function testOnePriorityListAppliesToEachLocaleBySource(): void
    {
        $anime = $this->animeWith('Old ru', 'plugin-q', 'ru');
        $anime->setDescription('en', 'Old en', 'plugin-p');
        $anime->setDescription('de', 'Old de', 'manual');
        $this->entityManager->flush();
        $writer = $this->writerWithPriority(['plugin-p', 'plugin-q']);

        foreach (['ru', 'en', 'de'] as $locale) {
            $writer->write($anime, new PluginId('plugin-p'), $locale, 'New '.$locale);
        }

        $rows = $this->rows($anime);
        ksort($rows);
        $this->assertSame(['de' => ['Old de', 'manual'], 'en' => ['New en', 'plugin-p'], 'ru' => ['New ru', 'plugin-p']], $rows);
    }

    public function testReplacementIsSkippedWhenTheRowBecameManualAfterTheSnapshot(): void
    {
        $anime = $this->animeWith('Old', 'plugin-q');
        $this->entityManager->getConnection()->executeStatement("UPDATE anime_description SET description = 'Mine', source = 'manual' WHERE anime_id = ?", [$anime->id]);

        $this->writerWithPriority(['plugin-p', 'plugin-q'])->write($anime, new PluginId('plugin-p'), 'ru', 'New');

        $this->assertSame(['ru' => ['Mine', 'manual']], $this->rows($anime));
    }

    private function persistedAnimeWithDescription(?string $text = null, ?string $source = null): Anime
    {
        $anime = new TvAnime();
        $anime->setTitle('Bleach')->setWatchStatus(WatchStatus::Plan);
        if ($text !== null) {
            $anime->setDescription('en', $text, $source);
        }
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    public function testInsertRecordsAChangeOfTheDescriptions(): void
    {
        $anime = $this->persistedAnimeWithDescription('Russian text', null);
        $probe = new CatalogEventProbe($this->entityManager);
        $writer = new PluginDescriptionWriter($this->entityManager, null, $probe->recorder);

        $writer->write($anime, new PluginId('animedb-shikimori'), 'ja', 'Japanese text');

        $this->assertCount(1, $probe->changed());
        $change = $probe->changed()[0];
        $this->assertSame($anime->id, $change->animeId);
        $this->assertSame(['descriptions'], array_keys($change->changes));
        $this->assertSame([['locale' => 'en', 'description' => 'Russian text', 'source' => null]], $change->changes['descriptions']->before);
        $this->assertSame([
            ['locale' => 'en', 'description' => 'Russian text', 'source' => null],
            ['locale' => 'ja', 'description' => 'Japanese text', 'source' => 'animedb-shikimori'],
        ], $change->changes['descriptions']->after);
    }

    public function testUpdateRecordsAChangeOfTheDescriptions(): void
    {
        $anime = $this->persistedAnimeWithDescription('', null);
        $probe = new CatalogEventProbe($this->entityManager);
        $writer = new PluginDescriptionWriter($this->entityManager, null, $probe->recorder);

        $writer->write($anime, new PluginId('animedb-shikimori'), 'en', 'Fresh text');

        $this->assertCount(1, $probe->changed());
        $changes = $probe->changed()[0]->changes;
        $this->assertSame([['locale' => 'en', 'description' => '', 'source' => null]], $changes['descriptions']->before);
        $this->assertSame([['locale' => 'en', 'description' => 'Fresh text', 'source' => 'animedb-shikimori']], $changes['descriptions']->after);
    }

    public function testAnInsertRejectedByOnConflictRecordsNothing(): void
    {
        $anime = $this->persistedAnimeWithDescription();
        $probe = new CatalogEventProbe($this->entityManager);
        $writer = new PluginDescriptionWriter($this->entityManager, null, $probe->recorder);
        // Another writer got the locale in between: the entity does not know the row yet.
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO anime_description (anime_id, locale, description, source) VALUES (?, ?, ?, ?)',
            [$anime->id, 'en', 'Taken', 'manual'],
        );

        $writer->write($anime, new PluginId('animedb-shikimori'), 'en', 'Late text');

        $this->assertSame([], $probe->events);
        $this->assertSame(['Taken', 'manual'], $this->rows($anime)['en']);
    }

    public function testAnUpdateThatTouchedNoRowRecordsNothing(): void
    {
        $anime = $this->persistedAnimeWithDescription('', null);
        $probe = new CatalogEventProbe($this->entityManager);
        $writer = new PluginDescriptionWriter($this->entityManager, null, $probe->recorder);
        // Another writer changed the row: the conditional UPDATE no longer matches.
        $this->entityManager->getConnection()->executeStatement('UPDATE anime_description SET description = ? WHERE anime_id = ?', ['Changed elsewhere', $anime->id]);

        $writer->write($anime, new PluginId('animedb-shikimori'), 'en', 'Fresh text');

        $this->assertSame([], $probe->events);
        $this->assertSame(['Changed elsewhere', null], $this->rows($anime)['en']);
    }
}
