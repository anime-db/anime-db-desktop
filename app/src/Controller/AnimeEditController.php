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

namespace App\Controller;

use App\Entity\Anime;
use App\Entity\AnimeName;
use App\Entity\AnimeSource;
use App\Entity\Enum\AnimeNameRole;
use App\Entity\Enum\Demographic;
use App\Entity\Enum\GenreCode;
use App\Entity\Enum\ThemeCode;
use App\Entity\LocaleNormalizer;
use App\Entity\SeriesAnime;
use App\Entity\Studio;
use App\Repository\StudioRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * The "Edit entry" page (issue #914): one form for every field a plugin can fill, so the user
 * has full control over their own catalog. Quick in-place editing on the card stays limited to
 * the user's own data (status, progress, rating, notes — {@see AnimeEditableController}).
 *
 * No Symfony Form here, same as {@see AnimeNewController}: values are read from the request,
 * the CSRF token is checked by hand. The type of the record is not editable.
 *
 * Collections are changed point-wise (removed rows deleted, new rows added, the rest left
 * alone), never cleared and refilled: AnimeDescription is unique per locale and the
 * UnitOfWork inserts before it deletes, see Anime::setDescription(). A source link edit does not
 * touch the cached external id used by sync. Everything goes through a single flush.
 */
final class AnimeEditController
{
    private const MAX_NAME_LENGTH = 256;
    private const MAX_URL_LENGTH = 512;
    private const STUDIO_CHOICES_LIMIT = 1000;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Environment $twig,
        private readonly StudioRepository $studios,
    ) {
    }

    #[Route('/anime/{id}/edit', name: 'anime_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(Anime $anime): Response
    {
        return $this->renderForm($anime, $this->formFromAnime($anime), []);
    }

    #[Route('/anime/{id}/edit', name: 'anime_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function update(Anime $anime, Request $request): Response
    {
        $token = new CsrfToken('anime_edit_'.$anime->id, (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }

        $form = $this->formFromRequest($request, $anime);
        $errors = $this->validate($form, $anime);
        if ($errors !== []) {
            return $this->renderForm($anime, $form, $errors);
        }

        $this->apply($anime, $form);
        $this->entityManager->flush();

        return new RedirectResponse($this->urlGenerator->generate('anime_show', ['id' => $anime->id]));
    }

    /**
     * @param array<string, mixed>  $form
     * @param array<string, string> $errors translation key per field ("names.2", "sources.0", ...)
     */
    private function renderForm(Anime $anime, array $form, array $errors): Response
    {
        return new Response($this->twig->render('anime/edit.html.twig', [
            'anime' => ['id' => $anime->id, 'title' => $anime->getTitle(), 'type' => $anime->getType()->value],
            'is_series' => $anime instanceof SeriesAnime,
            'form' => $form,
            'errors' => $errors,
            'genre_choices' => array_column(GenreCode::cases(), 'value'),
            'theme_choices' => array_column(ThemeCode::cases(), 'value'),
            'demographic_choices' => array_column(Demographic::cases(), 'value'),
            'role_choices' => array_column(AnimeNameRole::cases(), 'value'),
            'studio_choices' => array_map(
                static fn (Studio $studio): array => ['id' => (string) $studio->id, 'name' => $studio->name],
                $this->studios->findAllOrderedByName(self::STUDIO_CHOICES_LIMIT),
            ),
            'csrf_token_id' => 'anime_edit_'.$anime->id,
        ]));
    }

    /** @return array<string, mixed> */
    private function formFromAnime(Anime $anime): array
    {
        $descriptions = [];
        foreach ($anime->getDescriptions() as $description) {
            $descriptions[] = ['locale' => $description->locale, 'text' => $description->description];
        }

        return [
            'title' => $anime->getTitle(),
            'names' => array_map(
                static fn (AnimeName $name): array => ['name' => $name->name, 'locale' => $name->locale ?? '', 'role' => $name->role->value],
                $anime->getNames()->toArray(),
            ),
            'descriptions' => $descriptions,
            'genres' => array_map(static fn (GenreCode $code): string => $code->value, $anime->getGenreCodes()),
            'themes' => array_map(static fn (ThemeCode $code): string => $code->value, $anime->getThemeCodes()),
            'studios' => array_map(static fn (Studio $studio): string => (string) $studio->id, $anime->getStudios()->toArray()),
            'new_studios' => [],
            'demographic' => $anime->getDemographic()->value ?? '',
            'date_premiere' => $anime->getDatePremiere()?->format('Y-m-d') ?? '',
            'date_end' => $anime->getDateEnd()?->format('Y-m-d') ?? '',
            'duration_minutes' => (string) $anime->getDurationMinutes(),
            'episodes_count' => $anime instanceof SeriesAnime ? (string) $anime->getEpisodesCount() : '',
            'countries' => implode(', ', $anime->getCountries() ?? []),
            'sources' => array_map(static fn (AnimeSource $source): string => $source->url, $anime->getSources()->toArray()),
            'notes' => $anime->getNotes() ?? '',
        ];
    }

    /** @return array<string, mixed> */
    private function formFromRequest(Request $request, Anime $anime): array
    {
        $names = [];
        foreach ($this->rows($request, 'names') as $row) {
            $names[] = [
                'name' => $this->text($row['name'] ?? null),
                'locale' => $this->text($row['locale'] ?? null),
                'role' => $this->text($row['role'] ?? null),
            ];
        }

        $descriptions = [];
        foreach ($this->rows($request, 'descriptions') as $row) {
            $descriptions[] = [
                'locale' => $this->text($row['locale'] ?? null),
                'text' => $this->text($row['text'] ?? null),
            ];
        }

        return [
            'title' => $this->text($request->request->get('title')),
            'names' => $names,
            'descriptions' => $descriptions,
            'genres' => $this->strings($request, 'genres'),
            'themes' => $this->strings($request, 'themes'),
            'studios' => $this->strings($request, 'studios'),
            'new_studios' => $this->strings($request, 'new_studios'),
            'demographic' => $this->text($request->request->get('demographic')),
            'date_premiere' => $this->text($request->request->get('date_premiere')),
            'date_end' => $this->text($request->request->get('date_end')),
            'duration_minutes' => $this->text($request->request->get('duration_minutes')),
            'episodes_count' => $anime instanceof SeriesAnime ? $this->text($request->request->get('episodes_count')) : '',
            'countries' => $this->text($request->request->get('countries')),
            'sources' => $this->strings($request, 'sources'),
            'notes' => trim((string) $request->request->get('notes', '')),
        ];
    }

    /**
     * @param array<string, mixed> $form
     *
     * @return array<string, string> translation key per field
     */
    private function validate(array $form, Anime $anime): array
    {
        $errors = [];

        if ($form['title'] === '') {
            $errors['title'] = 'anime_edit.error_title_required';
        } elseif (mb_strlen($form['title']) > self::MAX_NAME_LENGTH) {
            $errors['title'] = 'anime_edit.error_too_long';
        }

        foreach ($form['names'] as $i => $row) {
            if ($row['name'] === '' && $row['locale'] === '') {
                continue;
            }
            if ($row['name'] === '') {
                $errors["names.$i"] = 'anime_edit.error_name_required';
            } elseif (mb_strlen($row['name']) > self::MAX_NAME_LENGTH) {
                $errors["names.$i"] = 'anime_edit.error_too_long';
            } elseif (AnimeNameRole::tryFrom($row['role']) === null) {
                $errors["names.$i"] = 'anime_edit.error_role_invalid';
            } elseif ($row['locale'] !== '' && LocaleNormalizer::normalize($row['locale']) === null) {
                $errors["names.$i"] = 'anime_edit.error_locale_invalid';
            }
        }

        $seenLocales = [];
        foreach ($form['descriptions'] as $i => $row) {
            if ($row['text'] === '') {
                continue;
            }
            $locale = LocaleNormalizer::normalize($row['locale']);
            if ($locale === null) {
                $errors["descriptions.$i"] = 'anime_edit.error_locale_invalid';
            } elseif (isset($seenLocales[$locale])) {
                $errors["descriptions.$i"] = 'anime_edit.error_locale_duplicate';
            }
            $seenLocales[$locale ?? ''] = true;
        }

        foreach (['genres' => GenreCode::class, 'themes' => ThemeCode::class] as $field => $enum) {
            foreach ($form[$field] as $value) {
                if ($enum::tryFrom($value) === null) {
                    $errors[$field] = 'anime_edit.error_choice_invalid';
                }
            }
        }

        foreach ($form['studios'] as $id) {
            if ($this->findStudio($id) === null) {
                $errors['studios'] = 'anime_edit.error_choice_invalid';
            }
        }
        foreach ($form['new_studios'] as $name) {
            if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
                $errors['new_studios'] = 'anime_edit.error_too_long';
            }
        }

        if ($form['demographic'] !== '' && Demographic::tryFrom($form['demographic']) === null) {
            $errors['demographic'] = 'anime_edit.error_choice_invalid';
        }

        $premiere = $this->parseDate($form['date_premiere']);
        $end = $this->parseDate($form['date_end']);
        if ($premiere === false) {
            $errors['date_premiere'] = 'anime_edit.error_date_invalid';
        }
        if ($end === false) {
            $errors['date_end'] = 'anime_edit.error_date_invalid';
        }
        if ($premiere instanceof \DateTimeImmutable && $end instanceof \DateTimeImmutable && $end < $premiere) {
            $errors['date_end'] = 'anime_edit.error_date_range';
        }

        foreach (['duration_minutes', 'episodes_count'] as $field) {
            if ($form[$field] !== '' && preg_match('/^\d{1,9}\z/', $form[$field]) !== 1) {
                $errors[$field] = 'anime_edit.error_number_invalid';
            }
        }

        if ($anime instanceof SeriesAnime && !isset($errors['episodes_count']) && $form['episodes_count'] !== ''
            && $anime->getWatchedEpisodes() > (int) $form['episodes_count']) {
            $errors['episodes_count'] = 'anime_edit.error_episodes_below_watched';
        }

        foreach ($this->parseCountries($form['countries']) as $code) {
            if (preg_match('/^[A-Z]{2}\z/', $code) !== 1) {
                $errors['countries'] = 'anime_edit.error_country_invalid';
            }
        }

        foreach ($form['sources'] as $i => $url) {
            if ($url !== '' && !$this->isValidUrl($url)) {
                $errors["sources.$i"] = 'anime_edit.error_url_invalid';
            }
        }

        return $errors;
    }

    /** @param array<string, mixed> $form already validated: nothing below throws on it */
    private function apply(Anime $anime, array $form): void
    {
        $anime->setTitle($form['title']);
        $this->applyNames($anime, $form['names']);
        $this->applyDescriptions($anime, $form['descriptions']);

        $genres = array_map(GenreCode::from(...), $form['genres']);
        foreach ($anime->getGenreCodes() as $code) {
            if (!\in_array($code, $genres, true)) {
                $anime->removeGenre($code);
            }
        }
        foreach ($genres as $code) {
            $anime->addGenre($code);
        }

        $themes = array_map(ThemeCode::from(...), $form['themes']);
        foreach ($anime->getThemeCodes() as $code) {
            if (!\in_array($code, $themes, true)) {
                $anime->removeTheme($code);
            }
        }
        foreach ($themes as $code) {
            $anime->addTheme($code);
        }

        $this->applyStudios($anime, $form['studios'], $form['new_studios']);
        $anime->setDemographic($form['demographic'] === '' ? null : Demographic::from($form['demographic']));

        $premiere = $this->parseDate($form['date_premiere']);
        $end = $this->parseDate($form['date_end']);
        $anime->setDatePremiereAndEnd($premiere ?: null, $end ?: null);

        // The domain rejects a zero duration, so "0" means "not set", like an empty field.
        $duration = (int) $form['duration_minutes'];
        $anime->setDurationMinutes($duration > 0 ? $duration : null);
        if ($anime instanceof SeriesAnime) {
            $anime->setEpisodesCount($form['episodes_count'] === '' ? null : (int) $form['episodes_count']);
        }

        $countries = $this->parseCountries($form['countries']);
        $anime->setCountries($countries === [] ? null : $countries);
        $this->applySources($anime, $form['sources']);
        $anime->setNotes($form['notes'] === '' ? null : $form['notes']);
    }

    /** @param list<array{name: string, locale: string, role: string}> $rows */
    private function applyNames(Anime $anime, array $rows): void
    {
        $wanted = [];
        foreach ($rows as $row) {
            if ($row['name'] === '') {
                continue;
            }
            $locale = LocaleNormalizer::normalize($row['locale']);
            $role = AnimeNameRole::from($row['role']);
            $wanted[$this->nameKey($row['name'], $locale, $role)] = [$row['name'], $locale, $role];
        }

        $kept = [];
        foreach ($anime->getNames()->toArray() as $existing) {
            $key = $this->nameKey($existing->name, $existing->locale, $existing->role);
            if (isset($wanted[$key])) {
                $kept[$key] = true;
            } else {
                $anime->removeName($existing);
            }
        }
        foreach ($wanted as $key => [$name, $locale, $role]) {
            if (!isset($kept[$key])) {
                $anime->addName($name, $locale, $role);
            }
        }
    }

    private function nameKey(string $name, ?string $locale, AnimeNameRole $role): string
    {
        return $name."\0".($locale ?? '')."\0".$role->value;
    }

    /** @param list<array{locale: string, text: string}> $rows */
    private function applyDescriptions(Anime $anime, array $rows): void
    {
        $wanted = [];
        foreach ($rows as $row) {
            $locale = LocaleNormalizer::normalize($row['locale']);
            if ($row['text'] !== '' && $locale !== null) {
                $wanted[$locale] = $row['text'];
            }
        }

        foreach ($anime->getDescriptions()->toArray() as $existing) {
            if (!isset($wanted[$existing->locale])) {
                $anime->removeDescription($existing->locale);
            }
        }
        foreach ($wanted as $locale => $text) {
            $anime->setDescription((string) $locale, $text);
        }
    }

    /**
     * @param list<string> $ids
     * @param list<string> $newNames
     */
    private function applyStudios(Anime $anime, array $ids, array $newNames): void
    {
        $wanted = [];
        foreach ($ids as $id) {
            $studio = $this->findStudio($id) ?? throw new \LogicException('Studio must be validated before apply.');
            $wanted[spl_object_id($studio)] = $studio;
        }
        foreach ($newNames as $name) {
            $studio = $this->studios->findOneByName($name);
            if ($studio === null) {
                $studio = new Studio();
                $studio->rename($name);
                $this->entityManager->persist($studio);
            }
            $wanted[spl_object_id($studio)] = $studio;
        }

        foreach ($anime->getStudios()->toArray() as $studio) {
            if (!isset($wanted[spl_object_id($studio)])) {
                $anime->removeStudio($studio);
            }
        }
        foreach ($wanted as $studio) {
            $anime->addStudio($studio);
        }
    }

    /** @param list<string> $urls */
    private function applySources(Anime $anime, array $urls): void
    {
        $wanted = array_flip(array_filter($urls, static fn (string $url): bool => $url !== ''));

        $present = [];
        foreach ($anime->getSources()->toArray() as $source) {
            if (isset($wanted[$source->url])) {
                $present[$source->url] = true;
            } else {
                $anime->removeSource($source);
            }
        }
        foreach (array_keys($wanted) as $url) {
            if (!isset($present[$url])) {
                $anime->addSource($url);
            }
        }
    }

    private function findStudio(string $id): ?Studio
    {
        return preg_match('/^\d+\z/', $id) === 1 ? $this->entityManager->find(Studio::class, (int) $id) : null;
    }

    /** @return \DateTimeImmutable|false|null null for an empty value, false for an invalid one */
    private function parseDate(string $value): \DateTimeImmutable|false|null
    {
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();

        return $date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) ? false : $date;
    }

    /** @return list<string> */
    private function parseCountries(string $value): array
    {
        $codes = preg_split('/[\s,;]+/', mb_strtoupper($value), -1, \PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique($codes === false ? [] : $codes));
    }

    private function isValidUrl(string $url): bool
    {
        return mb_strlen($url) <= self::MAX_URL_LENGTH
            && filter_var($url, \FILTER_VALIDATE_URL) !== false
            && \in_array(parse_url($url, \PHP_URL_SCHEME), ['http', 'https'], true);
    }

    private function text(mixed $value): string
    {
        return \is_string($value) ? trim($value) : '';
    }

    /** @return list<string> non-empty trimmed strings of a posted list field */
    private function strings(Request $request, string $field): array
    {
        $values = [];
        foreach ($request->request->all($field) as $value) {
            $value = $this->text($value);
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    /** @return list<array<array-key, mixed>> */
    private function rows(Request $request, string $field): array
    {
        return array_values(array_filter($request->request->all($field), \is_array(...)));
    }
}
