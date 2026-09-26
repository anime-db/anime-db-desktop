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

namespace App\Service\Install;

use App\Entity\Anime;
use App\Entity\Enum\AnimeNameRole;
use App\Entity\Enum\AnimeType;
use App\Entity\Enum\Demographic;
use App\Entity\Enum\GenreCode;
use App\Entity\Enum\ThemeCode;
use App\Entity\Enum\WatchStatus;
use App\Entity\Label;
use App\Entity\SeriesAnime;
use App\Entity\Studio;
use App\Repository\LabelRepository;
use App\Repository\StudioRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Seeds the catalog with 7 hand-picked sample anime (issue #181, step 7/7 of the install
 * wizard): called once from the onboarding banner's "Load demo data" button, never as a
 * console command or doctrine-fixtures-bundle fixture — this is opt-in production data,
 * not dev/test scaffolding. Not defensively guarded against a second call: the banner (and
 * therefore the button) only renders while the catalog is empty (see HomeController), so a
 * repeat call is not reachable through the UI.
 *
 * Every row gets the "Sample" label (find-or-create, same pattern as AnimeLabelController)
 * so the user can find/delete them later; there is deliberately no bulk-cleanup mechanism
 * (see issue #181 "Не входит в объём").
 */
class SampleAnimeSeeder
{
    private const LABEL_NAME = 'Sample';

    /**
     * Titles, studios, dates and source links are facts; the `descriptions` synopses are
     * original texts written for this project, not quotes from MAL, Shikimori or Wikipedia —
     * keep it that way when editing them.
     *
     * @var list<array{
     *     title: string,
     *     type: AnimeType,
     *     episodesCount: ?int,
     *     durationMinutes: ?int,
     *     studios: list<string>,
     *     genres: list<GenreCode>,
     *     themes: list<ThemeCode>,
     *     demographic: ?Demographic,
     *     cover: string,
     *     altNames: list<array{locale: ?string, role: AnimeNameRole, name: string}>,
     *     sources: list<string>,
     *     datePremiere: string,
     *     dateEnd: string,
     *     countries: list<string>,
     *     descriptions: array<string, string>,
     * }>
     */
    private const SAMPLES = [
        [
            'title' => 'Fullmetal Alchemist: Brotherhood',
            'type' => AnimeType::Tv,
            'episodesCount' => 64,
            'durationMinutes' => 24,
            'studios' => ['Bones'],
            'genres' => [GenreCode::Action, GenreCode::Adventure, GenreCode::Drama, GenreCode::Fantasy],
            'themes' => [ThemeCode::Military],
            'demographic' => Demographic::Shounen,
            'cover' => 'fullmetal-alchemist-brotherhood.webp',
            'altNames' => [
                ['locale' => 'ja', 'role' => AnimeNameRole::Official, 'name' => '鋼の錬金術師 FULLMETAL ALCHEMIST'],
                ['locale' => 'en', 'role' => AnimeNameRole::Official, 'name' => 'Fullmetal Alchemist: Brotherhood'],
                ['locale' => 'ru', 'role' => AnimeNameRole::Official, 'name' => 'Стальной алхимик: Братство'],
                ['locale' => null, 'role' => AnimeNameRole::Synonym, 'name' => 'Hagane no Renkinjutsushi: Fullmetal Alchemist'],
                ['locale' => null, 'role' => AnimeNameRole::Short, 'name' => 'FMA'],
                ['locale' => null, 'role' => AnimeNameRole::Short, 'name' => 'FMAB'],
            ],
            'sources' => [
                'https://myanimelist.net/anime/5114/Fullmetal_Alchemist__Brotherhood',
                'https://shikimori.io/animes/z5114-fullmetal-alchemist-brotherhood',
                'https://anidb.net/perl-bin/animedb.pl?show=anime&aid=6107',
                'https://www.animenewsnetwork.com/encyclopedia/anime.php?id=10216',
                'https://en.wikipedia.org/wiki/Fullmetal_Alchemist:_Brotherhood',
            ],
            'datePremiere' => '2009-04-05',
            'dateEnd' => '2010-07-04',
            'countries' => ['JP'],
            'descriptions' => [
                'en' => 'After a failed attempt to resurrect their dead mother, brothers Edward and Alphonse Elric '.
                    'sacrifice parts of their bodies to alchemy. Now Edward, a state alchemist known as the '.
                    "Fullmetal Alchemist, and Alphonse search for the legendary Philosopher's Stone to restore ".
                    'what they lost, while uncovering a conspiracy reaching the highest levels of their country.',
                'ru' => 'После неудачной попытки воскресить умершую мать братья Эдвард и Альфонс Элрики '.
                    'жертвуют алхимии частями своих тел. Эдвард, ставший государственным алхимиком по прозвищу '.
                    'Стальной, вместе с братом ищет легендарный Философский камень, чтобы вернуть утраченное, '.
                    'попутно раскрывая заговор, пронизывающий власть их страны.',
            ],
        ],
        [
            'title' => 'Spirited Away',
            'type' => AnimeType::Movie,
            'episodesCount' => null,
            'durationMinutes' => 124,
            'studios' => ['Studio Ghibli'],
            'genres' => [GenreCode::Adventure, GenreCode::AwardWinning, GenreCode::Fantasy],
            'themes' => [ThemeCode::Mythology],
            'demographic' => null,
            'cover' => 'spirited-away.webp',
            'altNames' => [
                ['locale' => 'ja', 'role' => AnimeNameRole::Official, 'name' => '千と千尋の神隠し'],
                ['locale' => 'en', 'role' => AnimeNameRole::Official, 'name' => 'Spirited Away'],
                ['locale' => 'ru', 'role' => AnimeNameRole::Official, 'name' => 'Унесённые призраками'],
                ['locale' => null, 'role' => AnimeNameRole::Synonym, 'name' => 'Sen to Chihiro no Kamikakushi'],
                ['locale' => null, 'role' => AnimeNameRole::Synonym, 'name' => "Sen and Chihiro's Spiriting Away"],
            ],
            'sources' => [
                'https://myanimelist.net/anime/199/Sen_to_Chihiro_no_Kamikakushi',
                'https://shikimori.io/animes/z199-sen-to-chihiro-no-kamikakushi',
                'https://anidb.net/perl-bin/animedb.pl?show=anime&aid=112',
                'https://www.animenewsnetwork.com/encyclopedia/anime.php?id=377',
                'https://en.wikipedia.org/wiki/Spirited_Away',
            ],
            'datePremiere' => '2001-07-20',
            'dateEnd' => '2001-07-20',
            'countries' => ['JP'],
            'descriptions' => [
                'en' => 'Ten-year-old Chihiro and her parents stumble into an abandoned amusement park that turns '.
                    'out to be a bathhouse for spirits. When her parents are transformed into pigs, Chihiro must '.
                    'work for the witch Yubaba to survive, save her family, and find a way back to the human world.',
                'ru' => 'Десятилетняя Тихиро и её родители случайно попадают в заброшенный парк развлечений, '.
                    'который оказывается баней для духов. После того как её родителей превращают в свиней, '.
                    'Тихиро вынуждена работать на колдунью Юбабу, чтобы выжить, спасти семью и вернуться в мир людей.',
            ],
        ],
        [
            'title' => 'Gintama',
            'type' => AnimeType::Tv,
            'episodesCount' => 201,
            'durationMinutes' => 24,
            'studios' => ['Sunrise'],
            'genres' => [GenreCode::Action, GenreCode::Comedy, GenreCode::SciFi],
            'themes' => [ThemeCode::Historical, ThemeCode::Parody, ThemeCode::GagHumor, ThemeCode::Samurai],
            'demographic' => Demographic::Shounen,
            'cover' => 'gintama.webp',
            'altNames' => [
                ['locale' => 'ja', 'role' => AnimeNameRole::Official, 'name' => '銀魂'],
                ['locale' => 'en', 'role' => AnimeNameRole::Official, 'name' => 'Gintama'],
                ['locale' => 'ru', 'role' => AnimeNameRole::Official, 'name' => 'Гинтама'],
                ['locale' => null, 'role' => AnimeNameRole::Synonym, 'name' => 'Gin Tama'],
                ['locale' => null, 'role' => AnimeNameRole::Synonym, 'name' => 'Silver Soul'],
                ['locale' => null, 'role' => AnimeNameRole::Synonym, 'name' => 'Yorinuki Gintama-san'],
            ],
            'sources' => [
                'https://myanimelist.net/anime/918/Gintama',
                'https://shikimori.io/animes/z918-gintama',
                'https://anidb.net/perl-bin/animedb.pl?show=anime&aid=3468',
                'https://www.animenewsnetwork.com/encyclopedia/anime.php?id=6236',
                'https://en.wikipedia.org/wiki/Gintama',
            ],
            'datePremiere' => '2006-04-04',
            'dateEnd' => '2010-03-25',
            'countries' => ['JP'],
            'descriptions' => [
                'en' => 'In an Edo-era Japan conquered by aliens who have outlawed swords, former samurai Gintoki '.
                    'Sakata scrapes together a living as an odd-jobs freelancer alongside his eccentric friends, '.
                    'taking on absurd requests that swing between slapstick comedy and heartfelt drama.',
                'ru' => 'В эпоху Эдо, захваченную инопланетянами, запретившими самураям носить мечи, бывший '.
                    'самурай Гинтоки Саката подрабатывает мастером на все руки вместе со своими эксцентричными '.
                    'друзьями, берясь за нелепые поручения, где грубоватый юмор соседствует с искренней драмой.',
            ],
        ],
        [
            'title' => 'Hellsing Ultimate',
            'type' => AnimeType::Ova,
            'episodesCount' => 10,
            'durationMinutes' => 49,
            'studios' => ['Madhouse', 'Satelight', 'Graphinica'],
            'genres' => [GenreCode::Action, GenreCode::Horror, GenreCode::Supernatural],
            'themes' => [ThemeCode::Military, ThemeCode::Vampire, ThemeCode::AdultCast, ThemeCode::Gore],
            'demographic' => Demographic::Seinen,
            'cover' => 'hellsing-ultimate.webp',
            'altNames' => [
                ['locale' => 'ja', 'role' => AnimeNameRole::Official, 'name' => 'ヘルシングOVA'],
                ['locale' => 'en', 'role' => AnimeNameRole::Official, 'name' => 'Hellsing Ultimate'],
                ['locale' => 'ru', 'role' => AnimeNameRole::Official, 'name' => 'Хеллсинг: Ультимат'],
            ],
            'sources' => [
                'https://myanimelist.net/anime/777/Hellsing_Ultimate',
                'https://shikimori.io/animes/777-hellsing-ultimate',
                'https://anidb.net/perl-bin/animedb.pl?show=anime&aid=3296',
                'https://www.animenewsnetwork.com/encyclopedia/anime.php?id=5114',
                'https://en.wikipedia.org/wiki/Hellsing_(OVA)',
            ],
            'datePremiere' => '2006-02-10',
            'dateEnd' => '2012-12-26',
            'countries' => ['JP'],
            'descriptions' => [
                'en' => 'The Hellsing Organization, led by Integra Hellsing, wages a secret war against vampires '.
                    'and other undead threatening England, spearheaded by its ultimate weapon: the ancient and '.
                    'merciless vampire Alucard, alongside his newly turned servant Seras Victoria.',
                'ru' => 'Организация «Хеллсинг» во главе с Интегрой Хеллсинг ведёт тайную войну против вампиров '.
                    'и прочей нежити, угрожающей Англии. Главное оружие организации — древний и беспощадный '.
                    'вампир Алукард, а также его новообращённая служанка Серас Виктория.',
            ],
        ],
        [
            'title' => 'Sousou no Frieren',
            'type' => AnimeType::Tv,
            'episodesCount' => 28,
            'durationMinutes' => 24,
            'studios' => ['Madhouse'],
            'genres' => [GenreCode::Adventure, GenreCode::AwardWinning, GenreCode::Drama, GenreCode::Fantasy],
            'themes' => [],
            'demographic' => Demographic::Shounen,
            'cover' => 'sousou-no-frieren.webp',
            'altNames' => [
                ['locale' => 'ja', 'role' => AnimeNameRole::Official, 'name' => '葬送のフリーレン'],
                ['locale' => 'en', 'role' => AnimeNameRole::Official, 'name' => 'Sousou no Frieren'],
                ['locale' => 'ru', 'role' => AnimeNameRole::Official, 'name' => 'Провожающая в последний путь Фрирен'],
                ['locale' => null, 'role' => AnimeNameRole::Synonym, 'name' => 'Frieren at the Funeral'],
                ['locale' => null, 'role' => AnimeNameRole::Synonym, 'name' => "Frieren: Beyond Journey's End"],
            ],
            'sources' => [
                'https://myanimelist.net/anime/52991/Sousou_no_Frieren',
                'https://shikimori.io/animes/52991-sousou-no-frieren',
                'https://anidb.net/perl-bin/animedb.pl?show=anime&aid=17617',
                'https://www.animenewsnetwork.com/encyclopedia/anime.php?id=26334',
                'https://en.wikipedia.org/wiki/Frieren#Anime',
            ],
            'datePremiere' => '2023-09-29',
            'dateEnd' => '2024-03-22',
            'countries' => ['JP'],
            'descriptions' => [
                'en' => "After the hero's party defeats the Demon King and returns home, elven mage Frieren ".
                    'realizes how little she truly understood her short-lived human companions during their '.
                    'decade-long journey. Decades later, she sets out on a new journey to come to terms with '.
                    'mortality and the meaning of the time she spent with them.',
                'ru' => 'После того как отряд героя побеждает Короля демонов и возвращается домой, '.
                    'эльфийка-волшебница Фрирен понимает, как мало она на самом деле знала о своих недолговечных '.
                    'человеческих спутниках за десять лет странствий. Десятилетия спустя она отправляется в новое '.
                    'путешествие, чтобы осмыслить смертность и значение проведённого с ними времени.',
            ],
        ],
        [
            'title' => 'One Punch Man',
            'type' => AnimeType::Tv,
            'episodesCount' => 12,
            'durationMinutes' => 24,
            'studios' => ['Madhouse'],
            'genres' => [GenreCode::Action, GenreCode::Comedy],
            'themes' => [ThemeCode::Parody, ThemeCode::SuperPower, ThemeCode::AdultCast],
            'demographic' => Demographic::Seinen,
            'cover' => 'one-punch-man.webp',
            'altNames' => [
                ['locale' => 'ja', 'role' => AnimeNameRole::Official, 'name' => 'ワンパンマン'],
                ['locale' => 'en', 'role' => AnimeNameRole::Official, 'name' => 'One Punch Man'],
                ['locale' => 'ru', 'role' => AnimeNameRole::Official, 'name' => 'Ванпанчмен'],
                ['locale' => null, 'role' => AnimeNameRole::Short, 'name' => 'OPM'],
            ],
            'sources' => [
                'https://myanimelist.net/anime/30276/One_Punch_Man',
                'https://shikimori.io/animes/z30276-one-punch-man',
                'https://anidb.net/perl-bin/animedb.pl?show=anime&aid=11123',
                'https://www.animenewsnetwork.com/encyclopedia/anime.php?id=16840',
                'https://en.wikipedia.org/wiki/One-Punch_Man',
            ],
            'datePremiere' => '2015-10-05',
            'dateEnd' => '2015-12-21',
            'countries' => ['JP'],
            'descriptions' => [
                'en' => 'Saitama is a superhero who, after three years of relentless training, has become so '.
                    'powerful that he can defeat any opponent with a single punch, leaving him bored and '.
                    'searching for a worthy challenge, while struggling to get the recognition he deserves.',
                'ru' => 'Сайтама — супергерой, который после трёх лет изнурительных тренировок стал настолько '.
                    'силён, что побеждает любого противника одним ударом. Из-за этого он смертельно скучает '.
                    'в поисках достойного соперника и никак не может добиться признания, которого заслуживает.',
            ],
        ],
        [
            'title' => 'Solo Leveling',
            'type' => AnimeType::Tv,
            'episodesCount' => 12,
            'durationMinutes' => 23,
            'studios' => ['A-1 Pictures'],
            'genres' => [GenreCode::Action, GenreCode::Adventure, GenreCode::Fantasy],
            'themes' => [ThemeCode::AdultCast, ThemeCode::UrbanFantasy],
            'demographic' => null,
            'cover' => 'solo-leveling.webp',
            'altNames' => [
                ['locale' => 'ja', 'role' => AnimeNameRole::Official, 'name' => '俺だけレベルアップな件'],
                ['locale' => 'en', 'role' => AnimeNameRole::Official, 'name' => 'Solo Leveling'],
                ['locale' => 'ru', 'role' => AnimeNameRole::Official, 'name' => 'Поднятие уровня в одиночку'],
                ['locale' => null, 'role' => AnimeNameRole::Synonym, 'name' => 'Na Honjaman Level Up'],
                // The Korean webtoon's own official title (unlike the romanization above): the
                // old mixed enum could only express this as a synonym, see issue #724.
                ['locale' => 'ko', 'role' => AnimeNameRole::Official, 'name' => '나 혼자만 레벨업'],
                ['locale' => null, 'role' => AnimeNameRole::Synonym, 'name' => 'I Level Up Alone'],
            ],
            'sources' => [
                'https://myanimelist.net/anime/52299/Ore_dake_Level_Up_na_Ken',
                'https://shikimori.io/animes/52299-ore-dake-level-up-na-ken',
                'https://anidb.net/perl-bin/animedb.pl?show=anime&aid=17495',
                'https://www.animenewsnetwork.com/encyclopedia/anime.php?id=26000',
                'https://en.wikipedia.org/wiki/Solo_Leveling',
            ],
            'datePremiere' => '2024-01-07',
            'dateEnd' => '2024-03-31',
            'countries' => ['JP', 'KR'],
            'descriptions' => [
                'en' => 'In a world where portals connect to deadly dungeons and only "Hunters" with '.
                    'supernatural abilities can fight the monsters within, Sung Jinwoo, the weakest Hunter of '.
                    'all mankind, gains a mysterious power that lets him grow stronger without limit after '.
                    'barely surviving a deadly double dungeon.',
                'ru' => 'В мире, где порталы ведут в смертоносные подземелья, а сражаться с чудовищами внутри '.
                    'способны лишь «Охотники» со сверхспособностями, Сон Джинву — слабейший Охотник среди людей '.
                    '— после того как еле выживает в смертельно опасном двойном подземелье, получает таинственную '.
                    'силу, позволяющую бесконечно расти.',
            ],
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StudioRepository $studios,
        private readonly LabelRepository $labels,
        private readonly string $mediaDir,
        private readonly string $sampleCoversDir,
    ) {
    }

    public function seed(): void
    {
        $label = $this->findOrCreateLabel();

        /** @var array<string, Studio> $studioCache local to this call — Doctrine's repository
         *  query cannot see a Studio persisted earlier in the same unflushed unit of work, so
         *  reusing the repository alone would create a duplicate row for every repeated studio
         *  name (e.g. Madhouse appears on 3 of the 7 titles below). */
        $studioCache = [];

        /** @var list<array{0: Anime, 1: string}> $pendingCovers */
        $pendingCovers = [];
        foreach (self::SAMPLES as $sample) {
            $studios = [];
            foreach ($sample['studios'] as $name) {
                $studios[] = $studioCache[$name] ??= $this->findOrCreateStudio($name);
            }

            $anime = $this->buildAnime($sample, $studios);
            $anime->addLabel($label);
            $this->entityManager->persist($anime);

            $pendingCovers[] = [$anime, $sample['cover']];
        }

        // Cover files are copied after this flush so every Anime already has the id its
        // media directory (%AppData%/media/{id}/) is keyed on.
        $this->entityManager->flush();

        foreach ($pendingCovers as [$anime, $cover]) {
            $this->attachCover($anime, $cover);
        }

        $this->entityManager->flush();
    }

    /**
     * @param array{title: string, type: AnimeType, episodesCount: ?int, durationMinutes: ?int, studios: list<string>, genres: list<GenreCode>, themes: list<ThemeCode>, demographic: ?Demographic, cover: string, altNames: list<array{locale: ?string, role: AnimeNameRole, name: string}>, sources: list<string>, datePremiere: string, dateEnd: string, countries: list<string>, descriptions: array<string, string>} $sample
     * @param list<Studio>                                                                                                                                                                                                                                                                                                                                                                                                $studios
     */
    private function buildAnime(array $sample, array $studios): Anime
    {
        $class = $sample['type']->entityClass();
        $anime = new $class();
        $anime->setTitle($sample['title'])
            ->setWatchStatus(WatchStatus::Plan)
            ->setDatePremiere(\DateTimeImmutable::createFromFormat('!Y-m-d', $sample['datePremiere']) ?: null)
            ->setDateEnd(\DateTimeImmutable::createFromFormat('!Y-m-d', $sample['dateEnd']) ?: null)
            ->setCountries($sample['countries']);

        if ($sample['durationMinutes'] !== null) {
            $anime->setDurationMinutes($sample['durationMinutes']);
        }

        if ($anime instanceof SeriesAnime && $sample['episodesCount'] !== null) {
            $anime->setEpisodesCount($sample['episodesCount']);
        }

        foreach ($studios as $studio) {
            $anime->addStudio($studio);
        }

        foreach ($sample['genres'] as $code) {
            $anime->addGenre($code);
        }

        foreach ($sample['themes'] as $code) {
            $anime->addTheme($code);
        }

        $anime->setDemographic($sample['demographic']);

        foreach ($sample['altNames'] as $altName) {
            $anime->addName($altName['name'], $altName['locale'], $altName['role']);
        }

        foreach ($sample['sources'] as $url) {
            $anime->addSource($url);
        }

        foreach ($sample['descriptions'] as $locale => $text) {
            $anime->setDescription($locale, $text);
        }

        return $anime;
    }

    private function findOrCreateLabel(): Label
    {
        $label = $this->labels->findOneByName(self::LABEL_NAME);
        if ($label !== null) {
            return $label;
        }

        $label = new Label(self::LABEL_NAME);
        $this->entityManager->persist($label);

        return $label;
    }

    private function findOrCreateStudio(string $name): Studio
    {
        $studio = $this->studios->findOneByName($name);
        if ($studio !== null) {
            return $studio;
        }

        $studio = new Studio();
        $studio->rename($name);
        $this->entityManager->persist($studio);

        return $studio;
    }

    /**
     * Copies the cover asset shipped under public/sample/ (issue #181) into
     * %AppData%/media/{id}/ using the same app-media:// scheme as regular covers
     * (issue #68). The actual image files are added separately from this change (see
     * public/sample/README.md) — a missing source file is not fatal, the anime is still
     * created without a cover rather than failing the whole seed.
     */
    private function attachCover(Anime $anime, string $filename): void
    {
        $sourcePath = rtrim($this->sampleCoversDir, '/\\').'/'.$filename;
        if (!is_file($sourcePath)) {
            return;
        }

        $targetDir = rtrim($this->mediaDir, '/\\').'/'.$anime->id;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0o777, true);
        }

        copy($sourcePath, $targetDir.'/'.$filename);
        $anime->setCover($filename);
    }
}
