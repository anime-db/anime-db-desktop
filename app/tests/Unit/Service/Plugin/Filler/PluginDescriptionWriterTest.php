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
use App\Service\Plugin\Filler\PluginDescriptionWriter;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

final class PluginDescriptionWriterTest extends TestCase
{
    private EntityManager $entityManager;
    private PluginDescriptionWriter $writer;

    protected function setUp(): void
    {
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
}
