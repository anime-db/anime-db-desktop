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

namespace App\Tests\Unit\Controller;

use App\Controller\AnimeEditController;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Anime;
use App\Entity\Enum\AnimeNameRole;
use App\Entity\Enum\Demographic;
use App\Entity\Enum\GenreCode;
use App\Entity\Enum\ThemeCode;
use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
use App\Entity\Studio;
use App\Entity\TvAnime;
use App\EventListener\AnimeAggregateTouchListener;
use App\EventListener\AnimeSearchIndexListener;
use App\Message\IndexAnimeMessage;
use App\Repository\StudioRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * Runs {@see AnimeEditController} against a real EntityManager (in-memory SQLite, schema built
 * from the entity attributes) so that what is asserted is what was actually flushed: the
 * point-wise collection changes and the UNIQUE(anime_id, locale) index of AnimeDescription are
 * only exercised by a real UnitOfWork.
 */
final class AnimeEditControllerTest extends TestCase
{
    private EntityManager $entityManager;

    /** @var array<string, mixed>|null parameters of the last render() call */
    private ?array $rendered = null;

    private bool $csrfValid = true;

    /** @var list<object> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        if (!Type::hasType(UnixTimestampType::NAME)) {
            Type::addType(UnixTimestampType::NAME, UnixTimestampType::class);
        }
        if (!Type::hasType(RatingType::NAME)) {
            Type::addType(RatingType::NAME, RatingType::class);
        }

        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 3).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->entityManager = new EntityManager($connection, $config);
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatched[] = $message;

            return new Envelope($message);
        });
        $eventManager = $this->entityManager->getEventManager();
        $eventManager->addEventListener(Events::onFlush, new AnimeAggregateTouchListener());
        $eventManager->addEventListener(Events::postUpdate, new AnimeSearchIndexListener($bus));
    }

    public function testGetRendersTheFormPrefilledFromTheEntry(): void
    {
        $anime = $this->persistTv(static function (TvAnime $anime): void {
            $anime->addName('Frieren', 'en', AnimeNameRole::Synonym);
            $anime->setDescription('en', 'An elf mage.');
            $anime->addGenre(GenreCode::Action);
            $anime->setEpisodesCount(28);
        });

        $response = $this->controller()->edit($anime);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Sousou no Frieren', $this->renderedParams()['form']['title']);
        $this->assertSame([['name' => 'Frieren', 'locale' => 'en', 'role' => 'synonym']], $this->renderedParams()['form']['names']);
        $this->assertSame([['locale' => 'en', 'text' => 'An elf mage.']], $this->renderedParams()['form']['descriptions']);
        $this->assertSame(['action'], $this->renderedParams()['form']['genres']);
        $this->assertSame('28', $this->renderedParams()['form']['episodes_count']);
        $this->assertSame([], $this->renderedParams()['errors']);
    }

    public function testPostWithAnInvalidCsrfTokenIsRejected(): void
    {
        $anime = $this->persistTv();
        $this->csrfValid = false;

        $this->expectException(BadRequestHttpException::class);

        $this->controller()->update($anime, $this->post([]));
    }

    public function testSuccessRedirectsToTheCard(): void
    {
        $anime = $this->persistTv();

        $response = $this->controller()->update($anime, $this->post([]));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/anime/'.$anime->id, $response->getTargetUrl());
    }

    public function testTitleIsSaved(): void
    {
        $anime = $this->persistTv();

        $this->controller()->update($anime, $this->post(['title' => '  Frieren: Beyond Journey\'s End ']));

        $this->assertSame('Frieren: Beyond Journey\'s End', $this->reload($anime)->getTitle());
    }

    public function testNamesAreAddedAndRemovedPointWise(): void
    {
        $anime = $this->persistTv(static function (TvAnime $anime): void {
            $anime->addName('Frieren', 'en', AnimeNameRole::Synonym);
            $anime->addName('葬送のフリーレン', 'ja', AnimeNameRole::Official);
        });
        $keptId = $this->nameId($anime, 'Frieren');

        $this->controller()->update($anime, $this->post([
            'names' => [
                ['name' => 'Frieren', 'locale' => 'en', 'role' => 'synonym'],
                ['name' => 'Frieren (short)', 'locale' => '', 'role' => 'short'],
            ],
        ]));

        $anime = $this->reload($anime);
        $names = array_map(static fn ($n): array => [$n->name, $n->locale, $n->role->value], $anime->getNames()->toArray());
        sort($names);
        $this->assertSame([['Frieren', 'en', 'synonym'], ['Frieren (short)', null, 'short']], $names);
        $this->assertSame($keptId, $this->nameId($anime, 'Frieren'), 'an unchanged row must not be recreated');
    }

    public function testEditingTheDescriptionOfAnExistingLocaleDoesNotHitTheUniqueIndex(): void
    {
        $anime = $this->persistTv(static function (TvAnime $anime): void {
            $anime->setDescription('en', 'Old text.');
            $anime->setDescription('ru', 'Старый текст.');
        });

        $this->controller()->update($anime, $this->post([
            'descriptions' => [
                ['locale' => 'en', 'text' => 'New text.'],
                ['locale' => 'ru', 'text' => 'Старый текст.'],
            ],
        ]));

        $anime = $this->reload($anime);
        $this->assertSame(['en' => 'New text.', 'ru' => 'Старый текст.'], $this->descriptions($anime));
    }

    public function testDescriptionLocalesAreAddedAndRemoved(): void
    {
        $anime = $this->persistTv(static function (TvAnime $anime): void {
            $anime->setDescription('en', 'English.');
        });

        $this->controller()->update($anime, $this->post([
            'descriptions' => [['locale' => 'ru', 'text' => 'Русский.']],
        ]));

        $this->assertSame(['ru' => 'Русский.'], $this->descriptions($this->reload($anime)));
    }

    public function testGenresAndThemesAreAddedAndRemoved(): void
    {
        $anime = $this->persistTv(static function (TvAnime $anime): void {
            $anime->addGenre(GenreCode::Action)->addGenre(GenreCode::Drama);
            $anime->addTheme(ThemeCode::Military);
        });

        $this->controller()->update($anime, $this->post([
            'genres' => ['drama', 'fantasy'],
            'themes' => ['school'],
        ]));

        $anime = $this->reload($anime);
        $genres = array_map(static fn (GenreCode $c): string => $c->value, $anime->getGenreCodes());
        sort($genres);
        $this->assertSame(['drama', 'fantasy'], $genres);
        $this->assertSame([ThemeCode::School], $anime->getThemeCodes());
    }

    public function testStudiosCanBePickedAndCreatedByName(): void
    {
        $existing = new Studio();
        $existing->rename('Madhouse');
        $removed = new Studio();
        $removed->rename('Old Studio');
        $this->entityManager->persist($existing);
        $this->entityManager->persist($removed);
        $anime = $this->persistTv(static fn (TvAnime $anime) => $anime->addStudio($removed));

        $this->controller()->update($anime, $this->post([
            'studios' => [(string) $existing->id],
            'new_studios' => ['Brand New', 'Madhouse'],
        ]));

        $studios = array_map(static fn (Studio $s): string => $s->name, $this->reload($anime)->getStudios()->toArray());
        sort($studios);
        $this->assertSame(['Brand New', 'Madhouse'], $studios);
        $this->assertCount(2, $this->entityManager->getRepository(Studio::class)->findBy(['name' => ['Madhouse', 'Brand New']]));
    }

    public function testDemographicCanBeSetAndCleared(): void
    {
        $anime = $this->persistTv();

        $this->controller()->update($anime, $this->post(['demographic' => 'seinen']));
        $this->assertSame(Demographic::Seinen, $this->reload($anime)->getDemographic());

        $this->controller()->update($this->reload($anime), $this->post(['demographic' => '']));
        $this->assertNull($this->reload($anime)->getDemographic());
    }

    public function testDatesDurationEpisodesCountriesAndNotesAreSaved(): void
    {
        $anime = $this->persistTv();

        $this->controller()->update($anime, $this->post([
            'date_premiere' => '2023-09-29',
            'date_end' => '2024-03-22',
            'duration_minutes' => '24',
            'episodes_count' => '28',
            'countries' => 'jp, US',
            'notes' => ' Rewatch. ',
        ]));

        $anime = $this->reload($anime);
        $this->assertInstanceOf(TvAnime::class, $anime);
        $this->assertSame('2023-09-29', $anime->getDatePremiere()?->format('Y-m-d'));
        $this->assertSame('2024-03-22', $anime->getDateEnd()?->format('Y-m-d'));
        $this->assertSame(24, $anime->getDurationMinutes());
        $this->assertSame(28, $anime->getEpisodesCount());
        $this->assertSame(['JP', 'US'], $anime->getCountries());
        $this->assertSame('Rewatch.', $anime->getNotes());
    }

    public function testEmptyOptionalFieldsClearTheStoredValues(): void
    {
        $anime = $this->persistTv(static function (TvAnime $anime): void {
            $anime->setDatePremiereAndEnd(new \DateTimeImmutable('2023-09-29'), new \DateTimeImmutable('2024-03-22'));
            $anime->setEpisodesCount(28);
            $anime->setDurationMinutes(24)->setCountries(['JP'])->setNotes('x');
        });

        $this->controller()->update($anime, $this->post([]));

        $anime = $this->reload($anime);
        $this->assertInstanceOf(TvAnime::class, $anime);
        $this->assertNull($anime->getDatePremiere());
        $this->assertNull($anime->getDateEnd());
        $this->assertNull($anime->getDurationMinutes());
        $this->assertNull($anime->getEpisodesCount());
        $this->assertNull($anime->getCountries());
        $this->assertNull($anime->getNotes());
    }

    public function testEpisodesAreIgnoredForANonSeriesType(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('Movie')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $response = $this->controller()->update($anime, $this->post(['episodes_count' => '12']));

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testSourcesAreAddedAndRemovedPointWise(): void
    {
        $anime = $this->persistTv(static function (TvAnime $anime): void {
            $anime->addSource('https://a.example/1');
            $anime->addSource('https://b.example/2');
        });
        $keptId = $this->sourceId($anime, 'https://a.example/1');

        $this->controller()->update($anime, $this->post(['sources' => ['https://a.example/1', 'https://c.example/3']]));

        $anime = $this->reload($anime);
        $urls = array_map(static fn ($s): string => $s->url, $anime->getSources()->toArray());
        sort($urls);
        $this->assertSame(['https://a.example/1', 'https://c.example/3'], $urls);
        $this->assertSame($keptId, $this->sourceId($anime, 'https://a.example/1'));
    }

    public function testOnlyChangingSourcesBumpsTheDateAndReindexesTheEntry(): void
    {
        $anime = $this->persistTv();
        $id = (int) $anime->id;
        $past = time() - 3_600;
        $this->entityManager->getConnection()->executeStatement('UPDATE anime SET dateUpdate = ? WHERE id = ?', [$past, $id]);
        $this->entityManager->clear();
        $this->dispatched = [];

        $this->controller()->update($this->reload($anime), $this->post(['sources' => ['https://a.example/1']]));

        $this->assertGreaterThan($past, (int) $this->entityManager->getConnection()->fetchOne('SELECT dateUpdate FROM anime WHERE id = ?', [$id]));
        $this->assertEquals([new IndexAnimeMessage($id)], $this->dispatched);
    }

    public function testANewAlternativeNameIsHandedToTheSearchIndex(): void
    {
        $anime = $this->persistTv();
        $this->dispatched = [];

        $this->controller()->update($anime, $this->post(['names' => [['name' => 'Frieren', 'locale' => 'en', 'role' => 'synonym']]]));

        $this->assertEquals([new IndexAnimeMessage((int) $anime->id)], $this->dispatched);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidInputProvider(): iterable
    {
        yield 'empty title' => [['title' => '  '], 'title'];
        yield 'too long title' => [['title' => str_repeat('a', 257)], 'title'];
        yield 'end before premiere' => [['date_premiere' => '2024-05-01', 'date_end' => '2024-04-01'], 'date_end'];
        yield 'bad date' => [['date_premiere' => '2024-13-45'], 'date_premiere'];
        yield 'negative duration' => [['duration_minutes' => '-5'], 'duration_minutes'];
        yield 'negative episodes' => [['episodes_count' => '-1'], 'episodes_count'];
        yield 'fractional duration' => [['duration_minutes' => '2.5'], 'duration_minutes'];
        yield 'episodes below watched' => [['episodes_count' => '2'], 'episodes_count'];
        yield 'bad url' => [['sources' => ['https://ok.example/', 'not a url']], 'sources.1'];
        yield 'non-http url' => [['sources' => ['ftp://example.com/x']], 'sources.0'];
        yield 'bad country' => [['countries' => 'JPN'], 'countries'];
        yield 'bad genre' => [['genres' => ['nope']], 'genres'];
        yield 'bad demographic' => [['demographic' => 'nope'], 'demographic'];
        yield 'unknown studio' => [['studios' => ['999']], 'studios'];
        yield 'bad name role' => [['names' => [['name' => 'X', 'locale' => '', 'role' => 'nope']]], 'names.0'];
        yield 'bad description locale' => [['descriptions' => [['locale' => '1', 'text' => 'x']]], 'descriptions.0'];
        yield 'duplicate description locale' => [['descriptions' => [['locale' => 'en', 'text' => 'a'], ['locale' => 'EN', 'text' => 'b']]], 'descriptions.1'];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidInputProvider')]
    public function testInvalidInputRerendersTheFormWithTheErrorAndSavesNothing(array $input, string $errorKey): void
    {
        $anime = $this->persistTv(static function (TvAnime $anime): void {
            $anime->setWatchedEpisodes(5);
        });

        $response = $this->controller()->update($anime, $this->post($input + ['notes' => 'typed notes']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayHasKey($errorKey, $this->renderedParams()['errors']);
        $this->assertSame('typed notes', $this->renderedParams()['form']['notes'], 'what the user typed must be kept');

        $this->entityManager->clear();
        $stored = $this->reload($anime);
        $this->assertSame('Sousou no Frieren', $stored->getTitle());
        $this->assertNull($stored->getNotes());
        $this->assertSame([], $stored->getSources()->toArray());
    }

    public function testInvalidInputKeepsTheTypedValuesInTheForm(): void
    {
        $anime = $this->persistTv();

        $this->controller()->update($anime, $this->post([
            'title' => '',
            'sources' => ['https://ok.example/', 'bad'],
            'duration_minutes' => '-3',
        ]));

        $this->assertSame(['https://ok.example/', 'bad'], $this->renderedParams()['form']['sources']);
        $this->assertSame('-3', $this->renderedParams()['form']['duration_minutes']);
    }

    /** @param (callable(TvAnime): mixed)|null $configure */
    private function persistTv(?callable $configure = null): TvAnime
    {
        $anime = new TvAnime();
        $anime->setTitle('Sousou no Frieren')->setWatchStatus(WatchStatus::Plan);
        if ($configure !== null) {
            $configure($anime);
        }
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    private function reload(Anime $anime): Anime
    {
        $this->entityManager->clear();

        return $this->entityManager->find(Anime::class, $anime->id) ?? throw new \LogicException('Anime vanished.');
    }

    /** @return array<string, string> */
    private function descriptions(Anime $anime): array
    {
        $result = [];
        foreach ($anime->getDescriptions() as $description) {
            $result[$description->locale] = $description->description;
        }

        return $result;
    }

    private function nameId(Anime $anime, string $name): ?int
    {
        foreach ($anime->getNames() as $row) {
            if ($row->name === $name) {
                return $row->id;
            }
        }

        return null;
    }

    private function sourceId(Anime $anime, string $url): ?int
    {
        foreach ($anime->getSources() as $row) {
            if ($row->url === $url) {
                return $row->id;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $fields */
    private function post(array $fields): Request
    {
        return new Request(request: ['_token' => 'token'] + $fields + ['title' => 'Sousou no Frieren']);
    }

    /** @return array<string, mixed> */
    private function renderedParams(): array
    {
        return $this->rendered ?? throw new \LogicException('The form was not rendered.');
    }

    private function controller(): AnimeEditController
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(fn (): bool => $this->csrfValid);

        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $name, array $params): string => '/anime/'.$params['id']);

        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $template, array $params): string {
            $this->rendered = $params;

            return '';
        });

        return new AnimeEditController($this->entityManager, $csrf, $urls, $twig, new StudioRepository($this->entityManager));
    }
}
