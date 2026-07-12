# Обложки для демо-данных (issue #181)

`App\Service\Install\SampleAnimeSeeder` при сидинге копирует отсюда обложку каждого демо-тайтла
в `%AppData%/media/{id}/` (тот же механизм, что и обычные обложки, issue #68).

Готовых файлов под этот список нет — сервис намеренно не падает, если файл отсутствует
(демо-тайтл создаётся без обложки). Ожидаемые имена файлов:

| Тайтл                            | Файл                                    |
|-----------------------------------|-------------------------------------------|
| Fullmetal Alchemist: Brotherhood   | `fullmetal-alchemist-brotherhood.webp`     |
| Spirited Away                      | `spirited-away.webp`                       |
| Gintama                            | `gintama.webp`                             |
| Hellsing Ultimate                  | `hellsing-ultimate.webp`                   |
| Sousou no Frieren                  | `sousou-no-frieren.webp`                   |
| One Punch Man                      | `one-punch-man.webp`                       |
| Solo Leveling                      | `solo-leveling.webp`                       |

Формат — `webp` (см. `native/protocols/app-media.js`, whitelist MIME-типов допускает также
`jpg`/`jpeg`/`png`/`gif`).
