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

namespace App\EventListener;

use App\Entity\AnimeSource;
use App\Service\Catalog\SourceUrlNormalizer;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/**
 * Fills `anime_source.normalized_url` of a new source. `AnimeSource::$url` is read-only and the
 * edit form removes and adds links instead of changing them, so no preUpdate hook is needed.
 */
#[AsDoctrineListener(event: Events::prePersist)]
final class AnimeSourceNormalizedUrlListener
{
    public function __construct(private readonly SourceUrlNormalizer $normalizer)
    {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $source = $args->getObject();
        if ($source instanceof AnimeSource) {
            $source->useNormalizedUrl($this->normalizer->normalize($source->url));
        }
    }
}
