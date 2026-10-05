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

use App\Entity\Download;
use App\Entity\Enum\DownloadStatus;
use App\Repository\DownloadRepository;
use App\Service\Download\DownloadActionOutcome;
use App\Service\Download\DownloadActionService;
use App\Service\Download\DownloadIncomingChecker;
use App\Service\Download\DownloadsOverviewBuilder;
use App\Service\Exception\QbittorrentClientException;
use App\Service\Qbittorrent\QbittorrentClient;
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
 * "Downloads" page's (issue #854) action buttons (issue #856): pause/resume, retry a Failed row,
 * stop seeding a Completed one, delete a row (optionally its files in qBittorrent too), and remove
 * a "no card" torrent straight from the client. Every command aimed at a specific torrent is
 * addressed by the `hash` qBittorrent's own `torrents/info` reports for this row's infoHash, never
 * by that v1 infoHash itself — see QbittorrentClient's and DownloadCompletionPoller's docblocks for
 * why a v1 hash is not always accepted as a command target.
 *
 * Every action that mutates a `downloads` row (retry, delete) commits to the database through
 * {@see DownloadActionService} BEFORE this controller calls qBittorrent at all — a concurrent
 * DownloadCompletionPoller pass changing the row between page load and this request surfaces as
 * "state changed", never a silent overwrite. That check compares against the `version`/`status`
 * the retry/delete forms carry as hidden fields (what the page actually rendered), via
 * {@see parseExpectedState()} — never against $download itself, which Symfony already re-loaded
 * fresh for this request and may therefore already reflect a poller pass the human never saw. A
 * qBittorrent call that fails afterwards (the torrent stays in the client, or a pause/resume/retry's
 * resume does nothing) is swallowed the same way {@see DownloadsController} already degrades a
 * qBittorrent outage — there is no calling UI context left by the time one of these requests
 * reaches the client call.
 *
 * Never deletes a file or directory itself: `deleteFiles` is only ever forwarded to
 * {@see QbittorrentClient::delete()}, the only thing in this app that can remove torrent data —
 * and only when the fresh `torrents/info` shows the data still under the storage's hidden incoming
 * directory (issue #899, {@see DownloadIncomingChecker}); a checkbox without that is silently
 * downgraded to deleteFiles=false, since data moved out of incoming is library content.
 */
final class DownloadActionController
{
    public function __construct(
        private readonly DownloadActionService $actions,
        private readonly DownloadRepository $downloads,
        private readonly QbittorrentClient $client,
        private readonly DownloadsOverviewBuilder $overviewBuilder,
        private readonly DownloadIncomingChecker $incomingChecker,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/downloads/{id}/pause', name: 'download_pause', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function pause(Download $download, Request $request): Response
    {
        $this->assertValidCsrfToken('download_pause_'.$this->requireId($download), $request);

        if (!$download->canBePausedOrResumed()) {
            return $this->renderIndexWithError('downloads.action_error_refused');
        }

        $torrent = $this->findTorrent($download->getInfoHash());
        if ($torrent !== null) {
            $this->tryClientCall(fn () => $this->client->stop((string) $torrent['hash']));
        }

        return $this->redirectToIndex();
    }

    #[Route('/downloads/{id}/resume', name: 'download_resume', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function resume(Download $download, Request $request): Response
    {
        $this->assertValidCsrfToken('download_resume_'.$this->requireId($download), $request);

        if (!$download->canBePausedOrResumed()) {
            return $this->renderIndexWithError('downloads.action_error_refused');
        }

        $torrent = $this->findTorrent($download->getInfoHash());
        if ($torrent !== null) {
            $this->tryClientCall(fn () => $this->client->start((string) $torrent['hash']));
        }

        return $this->redirectToIndex();
    }

    /**
     * Failed => Pending (see Download::retry()), then resumes the torrent in the client so
     * DownloadCompletionPoller's next pass has something to actually poll progress on — a Failed
     * row's torrent may have been paused (DownloadCompletionPoller::failIfOutOfSpace()) or may
     * already be running (a move/name-conflict failure never stops it), and start() is a no-op
     * either way for an already-running torrent.
     */
    #[Route('/downloads/{id}/retry', name: 'download_retry', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function retry(Download $download, Request $request): Response
    {
        $this->assertValidCsrfToken('download_retry_'.$this->requireId($download), $request);

        $expected = $this->parseExpectedState($request);
        if ($expected === null) {
            return $this->renderIndexWithError('downloads.action_error_conflict');
        }

        $outcome = $this->actions->retry($download, $expected[0], $expected[1]);
        if ($outcome !== DownloadActionOutcome::Success) {
            return $this->renderIndexWithError($this->outcomeErrorKey($outcome));
        }

        $torrent = $this->findTorrent($download->getInfoHash());
        if ($torrent !== null) {
            $this->tryClientCall(fn () => $this->client->start((string) $torrent['hash']));
        }

        return $this->redirectToIndex();
    }

    /**
     * Completed only: `torrents/delete` with deleteFiles=false. The `downloads` row and whatever
     * storage/storage_path pointer it recorded on its anime (issue #837) are never touched — a
     * seeding-stopped row stays a normal, visible "Completed" entry, not a removed one. See
     * DownloadUnlinkController (issue #857) for the separate action that does remove the row.
     */
    #[Route('/downloads/{id}/stop-seeding', name: 'download_stop_seeding', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function stopSeeding(Download $download, Request $request): Response
    {
        $this->assertValidCsrfToken('download_stop_seeding_'.$this->requireId($download), $request);

        if (!$download->isCompleted()) {
            return $this->renderIndexWithError('downloads.action_error_refused');
        }

        $torrent = $this->findTorrent($download->getInfoHash());
        if ($torrent !== null) {
            $this->tryClientCall(fn () => $this->client->delete((string) $torrent['hash'], false));
        }

        return $this->redirectToIndex();
    }

    /**
     * Pending or Failed: the `downloads` row is deleted first (see DownloadActionService::delete()'s
     * version+status-conditional DELETE), and only once that succeeds is the torrent itself removed
     * from qBittorrent — with $deleteFiles taken from the page's checkbox AND'ed with "the torrent's
     * live content_path is under the row's target storage's incoming directory" (re-checked on the
     * torrent fetched in this request, never on what the page showed), or simply never sent to the
     * client at all when this row had no torrent to begin with ("missing from client").
     */
    #[Route('/downloads/{id}/delete', name: 'download_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Download $download, Request $request): Response
    {
        $this->assertValidCsrfToken('download_delete_'.$this->requireId($download), $request);

        $expected = $this->parseExpectedState($request);
        if ($expected === null) {
            return $this->renderIndexWithError('downloads.action_error_conflict');
        }

        $torrent = $this->findTorrent($download->getInfoHash());
        $deleteFiles = $request->request->getBoolean('delete_files')
            && $this->incomingChecker->canDeleteDataOf($download, $torrent);

        $outcome = $this->actions->delete($download, $expected[0], $expected[1]);
        if ($outcome !== DownloadActionOutcome::Success) {
            return $this->renderIndexWithError($this->outcomeErrorKey($outcome));
        }

        if ($torrent !== null) {
            $this->tryClientCall(fn () => $this->client->delete((string) $torrent['hash'], $deleteFiles));
        }

        return $this->redirectToIndex();
    }

    /**
     * A "no card" torrent (no `downloads` row at all) — removed from the client; its data is
     * deleted too only if the form asked for it AND the fresh `torrents/info` shows it under the
     * incoming directory of some storage (issue #899), otherwise deleteFiles=false.
     *
     * Re-checks that no row claims this infoHash now, not just whatever the page was rendered
     * with: between that render and this click a card may have been added for it (or the page's
     * tab is simply stale), and a row's torrent has to stay in the client for
     * DownloadCompletionPoller to ever move that row forward.
     */
    #[Route('/downloads/orphan/{infoHash}/delete', name: 'download_delete_orphan', requirements: ['infoHash' => '[0-9a-f]{40}'], methods: ['POST'])]
    public function deleteOrphan(string $infoHash, Request $request): Response
    {
        $this->assertValidCsrfToken('download_delete_orphan_'.$infoHash, $request);

        if ($this->downloads->findByInfoHash($infoHash) !== []) {
            return $this->renderIndexWithError('downloads.action_error_refused');
        }

        $torrent = $this->findTorrent($infoHash);
        if ($torrent !== null) {
            $deleteFiles = $request->request->getBoolean('delete_files')
                && $this->incomingChecker->isInIncomingOfAnyStorage($torrent);
            $this->tryClientCall(fn () => $this->client->delete((string) $torrent['hash'], $deleteFiles));
        }

        return $this->redirectToIndex();
    }

    private function requireId(Download $download): int
    {
        return $download->id ?? throw new \LogicException('Download must be persisted before it can be acted on.');
    }

    /**
     * Reads the `version`/`status` hidden fields retry/delete's forms carry (see
     * DownloadsOverviewBuilder::buildRow() and downloads/index.html.twig) — the row state the human
     * actually saw when the page was rendered, not whatever $download now holds. Missing or
     * unparseable fields (a tampered request, or a form rendered before this pair of fields
     * existed) are treated the same as a mismatch: refuse to guess, and let the caller report
     * "state changed" without touching the database or qBittorrent at all.
     *
     * @return ?array{0: int, 1: DownloadStatus}
     */
    private function parseExpectedState(Request $request): ?array
    {
        $version = $request->request->get('version');
        if (!\is_string($version) || !ctype_digit($version)) {
            return null;
        }

        $status = $request->request->get('status');
        $status = \is_string($status) ? DownloadStatus::tryFrom($status) : null;
        if ($status === null) {
            return null;
        }

        return [(int) $version, $status];
    }

    private function assertValidCsrfToken(string $tokenId, Request $request): void
    {
        $token = new CsrfToken($tokenId, (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }
    }

    /**
     * Resolves this row's torrent fresh from qBittorrent rather than trusting anything the page
     * was rendered with — eligibility shown in the UI (see DownloadsOverviewBuilder::actionFields())
     * can be a poll interval stale by the time the user actually clicks a button.
     *
     * @return ?array<string, mixed>
     */
    private function findTorrent(string $infoHash): ?array
    {
        try {
            foreach ($this->client->getTorrentsInfo() as $torrent) {
                if (($torrent['infohash_v1'] ?? null) === $infoHash) {
                    return $torrent;
                }
            }
        } catch (QbittorrentClientException) {
            return null;
        }

        return null;
    }

    private function tryClientCall(callable $call): void
    {
        try {
            $call();
        } catch (QbittorrentClientException) {
            // See class docblock: a qBittorrent failure here is swallowed the same way
            // DownloadsController already degrades the page itself.
        }
    }

    private function outcomeErrorKey(DownloadActionOutcome $outcome): string
    {
        return match ($outcome) {
            DownloadActionOutcome::Conflict => 'downloads.action_error_conflict',
            DownloadActionOutcome::Refused => 'downloads.action_error_refused',
            DownloadActionOutcome::Success => throw new \LogicException('Success is not an error outcome.'),
        };
    }

    private function renderIndexWithError(string $error): Response
    {
        [$torrents, $qbittorrentAvailable] = $this->fetchTorrents();
        $overview = $this->overviewBuilder->build($torrents, $qbittorrentAvailable);

        return new Response($this->twig->render('downloads/index.html.twig', [
            'rows' => $overview['rows'],
            'orphans' => $overview['orphans'],
            'qbittorrentAvailable' => $qbittorrentAvailable,
            'actionError' => $error,
        ]));
    }

    private function redirectToIndex(): RedirectResponse
    {
        return new RedirectResponse($this->urlGenerator->generate('downloads_index'));
    }

    /** @return array{0: list<array<string, mixed>>, 1: bool} */
    private function fetchTorrents(): array
    {
        try {
            return [$this->client->getTorrentsInfo(), true];
        } catch (QbittorrentClientException) {
            return [[], false];
        }
    }
}
