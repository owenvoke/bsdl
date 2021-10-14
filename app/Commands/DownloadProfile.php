<?php

namespace App\Commands;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use LaravelZero\Framework\Commands\Command;

class DownloadProfile extends Command
{
    /** @var string */
    protected $signature = 'download:profile {username}';

    public function handle()
    {
        $username = $this->argument('username');

        $songsToDownload = collect();

        $this->info("Downloading song list for user: {$username}");

        while (true) {
            $response = Http::get('https://bsaber.com/wp-json/bsaber-api/songs', [
                'bookmarked_by' => $username,
                'page' => $page ??= 1,
                'count' => 100,
            ])->json();

            $page++;

            if (empty($response['songs'])) {
                break;
            }

            $songs = collect($response['songs'])
                ->filter(fn ($value, $key) => $value['hash'] !== '' && $value['song_key'] !== '')
                ->mapWithKeys(function ($item, $key) {
                    return [$item['hash'] => array_merge($item, ['filename' => "{$item['hash']}.zip"])];
                });

            $songsToDownload = $songsToDownload->merge($songs);

            $this->line("  - Added {$songs->count()} songs to the download list");
        }

        $this->line('');

        $this->info('Downloading songs...');

        $songsToDownload->each(function ($item, $key) {
            if (Storage::disk('local')->exists("songs/{$key}.zip")) {
                $this->task("  - Already downloaded: {$item['title']}");

                return;
            }

            $this->task("  - Downloading: {$item['title']}", function () use ($key, $item) {
                $response = Http::withOptions([
                    \GuzzleHttp\RequestOptions::SINK => Storage::disk('local')->path("songs/{$key}.zip"),
                ])->get("https://beatsaver.com/api/download/key/{$item['song_key']}");

                return $response->ok();
            });
        });

        $this->line('');

        $this->task('Generating metadata file...', function () use ($songsToDownload) {
            return Storage::disk('local')->put('songs/metadata.json', json_encode($songsToDownload->all()));
        });

        $this->line('');

        $this->info('Finished downloading all songs...');
    }
}
