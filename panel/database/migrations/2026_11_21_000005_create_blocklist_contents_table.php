<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The checked entries of a blocklist (E-170), moved from files to the
 * database: the web requests and the queue worker run as different users,
 * and a directory one of them created was closed to the other, so the
 * worker loaded an empty list onto the server. Lists whose file cannot be
 * read are fetched again at the next run of owpanel:blocklists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocklist_contents', function (Blueprint $table) {
            $table->foreignUlid('blocklist_id')->primary()->constrained()->cascadeOnDelete();
            // One network per line; at most 250000 lines.
            $table->longText('entries');
        });

        $root = storage_path('app/private/blocklists');
        foreach (DB::table('blocklists')->pluck('id') as $id) {
            $file = $root.'/'.$id.'.txt';
            $text = is_file($file) && is_readable($file) ? @file_get_contents($file) : false;
            if (is_string($text) && $text !== '') {
                DB::table('blocklist_contents')->insert(['blocklist_id' => $id, 'entries' => $text]);
                @unlink($file);
            } else {
                DB::table('blocklists')->where('id', $id)->update(['content_hash' => null, 'checked_at' => null, 'entries' => 0]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('blocklist_contents');
    }
};
