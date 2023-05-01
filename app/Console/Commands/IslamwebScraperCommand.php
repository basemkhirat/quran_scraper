<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use KubAT\PhpSimple\HtmlDomParser;
use Illuminate\Support\Facades\DB;

class IslamwebScraperCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'islamweb:scraper';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get recitations from Islamweb Website';

    /**
     * The source ID
     *
     * @var int
     */
    const SOURCE_ID = 2;

    /**
     * The source Slug
     *
     * @var string
     */
    const SOURCE_SLUG = "islamweb";

    function __construct()
    {
        parent::__construct();
        defined("MAX_FILE_SIZE") ?? define('MAX_FILE_SIZE', 600000000);
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        // Fetch narrations/telawat type for the first time
        // $this->syncNarrationsMappings();
        // $this->syncTelawat();

        $reciters = $this->fetchReciters();

        foreach ($reciters as $reciter) {

            // saving reciter if not exist

            $reciter_row = DB::table("reciters")
                ->where("source_id", self::SOURCE_ID)
                ->where("remote_id", $reciter->id)
                ->first();

            if ($reciter_row) {
                break;
            } else {
                DB::table("reciters")->insert([
                    "source_id" => self::SOURCE_ID,
                    "remote_id" => $reciter->id,
                    "remote_name" => $reciter->name
                ]);

                $reciter_row = DB::table("reciters")
                    ->where("source_id", self::SOURCE_ID)
                    ->where("remote_id", $reciter->id)
                    ->first();

                $this->info("Reciter: " . $reciter_row->id);
            }

            $html = HtmlDomParser::file_get_html($reciter->url);

            foreach ($html->find(".rwayabar") as $rewaya_bar) {
                $narration_name = trim($rewaya_bar->find("h1", 0)->plaintext);
                $narration_id = DB::table("narrations_mappings")
                    ->where("source_id", self::SOURCE_ID)
                    ->where("remote_name", $narration_name)
                    ->first()->narration_id;

                $content = $rewaya_bar->parent()->next_sibling()->find(".qurntitle h2", 0);
                $telawa_name = trim($content->plaintext);
                $telawa_id = DB::table("telawat")
                    ->where("source_id", self::SOURCE_ID)
                    ->where("remote_name", $telawa_name)
                    ->first()
                    ->id;

                // save the collection
                $collection_title = "مصحف " . $reciter->name . " - " . $telawa_name;
                $directory_name = $reciter_row->remote_name . "-" . $narration_name . "-" . $telawa_name;

                $collection_row = DB::table("collections")
                    ->where("source_id", self::SOURCE_ID)
                    ->where("reciter_id", $reciter_row->id)
                    ->where("narration_id", $narration_id)
                    ->where("telawa_id", $telawa_id)
                    ->first();

                if (!$collection_row) {
                    DB::table("collections")->insert([
                        "source_id" => self::SOURCE_ID,
                        "reciter_id" => $reciter_row->id,
                        "narration_id" => $narration_id,
                        "telawa_id" => $telawa_id,
                        "directory_name" => $directory_name,
                        "url" => $reciter->url,
                        "title" => $collection_title,
                    ]);

                    $collection_row = DB::table("collections")
                        ->where("source_id", self::SOURCE_ID)
                        ->where("reciter_id", $reciter_row->id)
                        ->where("narration_id", $narration_id)
                        ->where("telawa_id", $telawa_id)
                        ->first();

                    $this->line("Collection: " .  $collection_row->id);
                }

                $path = storage_path("quran/" . self::SOURCE_SLUG . "/" . $directory_name);

                if (!file_exists($path)) {
                    @mkdir($path, 0755, true);
                }

                // fetch recitations

                $rows = $rewaya_bar->parent()->next_sibling()->find("ul li");

                foreach ($rows as $row) {

                    $recitation_link = $row->find("h2 a", 0);
                    $recitation_id = (int) get_string_between($recitation_link->href, "audioid=", "&");
                    $recitation_name = trim($recitation_link->plaintext);
                    $mp3_url = $row->find(".floatl a", 0)->url;
                    $url_parts = explode(".", basename($mp3_url));
                    $sura_number = (int) $url_parts[0];
                    $file_path = $path . "/" . $sura_number . ".mp3";

                    if (!file_exists($file_path)) {
                        $mp3_content = fetchUrl($mp3_url, "https://audio.islamweb.net/");
                        file_put_contents($file_path, $mp3_content);
                    }

                    $recitation_row = DB::table("recitations")
                        ->where("source_id", self::SOURCE_ID)
                        ->where("collection_id", $collection_row->id)
                        ->where("remote_id", $recitation_id)
                        ->first();

                    if (!$recitation_row) {

                        $this->line("Recitation: " .  $mp3_url);

                        DB::table("recitations")->insert([
                            "source_id" => self::SOURCE_ID,
                            "collection_id" => $collection_row->id,
                            "remote_id" => $recitation_id,
                            "title" => $recitation_name,
                            "sura" => $sura_number,
                            "file_name" => $sura_number . ".mp3",
                            "url" => $mp3_url
                        ]);

                    }
                }
            }
        }

        $this->info("update done");
    }

    /**
     * Getting new reciters links
     * @param string $link
     */
    protected function fetchReciters()
    {

        $this->info("checking reciters..");

        $links = [];

        $url = "https://audio.islamweb.net/audio/index.php?page=qareelast";

        $html = HtmlDomParser::file_get_html($url);

        $a_tags = $html->find('h2 a[itemprop="url"]');

        foreach ($a_tags as $a) {
            $reciter_id = (int) get_string_between($a->href, "&qid=", "&");

            $links[] = (object) [
                "id" => $reciter_id,
                "name" => trim($a->plaintext),
                "url" => "https://audio.islamweb.net/audio/" . $a->href,
            ];
        }

        return $links;
    }


    /**
     * Sync Telawat
     */
    public function syncTelawat()
    {

        $this->info("checking Telawat..");

        $link = "https://audio.islamweb.net/audio/index.php?page=telawa";

        $html = HtmlDomParser::str_get_html(file_get_contents($link));

        $types = [];

        $index = 0;

        foreach ($html->find(".rwayabar") as $row) {

            $first_reciter_link = $row->parent()->next_sibling()->find("a", 0)->href;

            $telawa_id = (int) get_string_between($first_reciter_link, "&tid=", "&");

            $types[] = (object) [
                "source_id" => self::SOURCE_ID,
                "remote_id" => $telawa_id,
                "remote_name" => trim($row->find("h1", 0)->plaintext)
            ];

            $index++;
        }

        foreach ($types as $type) {
            $narration_row = DB::table("telawat")
                ->where("source_id", self::SOURCE_ID)
                ->where("remote_id", $type->remote_id)
                ->first();

            if ($narration_row) {
                DB::table("telawat")
                    ->where("source_id", self::SOURCE_ID)
                    ->where("remote_id", $type->remote_id)
                    ->update([
                        "remote_name" => $type->remote_name
                    ]);
            } else {
                DB::table("telawat")->insert([
                    "source_id" => self::SOURCE_ID,
                    "remote_id" => $type->remote_id,
                    "remote_name" => $type->remote_name
                ]);
            }
        }
    }

    /**
     * Sync Narrations Mappings
     */
    public function syncNarrationsMappings()
    {

        $this->info("checking narrations..");

        $link = "https://audio.islamweb.net/audio/index.php?page=rewaya";

        $html = HtmlDomParser::str_get_html(file_get_contents($link));

        $narrations = [];

        foreach ($html->find(".rwayabar") as $row) {
            $narrations[] = (object) [
                "source_id" => self::SOURCE_ID,
                "remote_id" => (int) $row->find("ol a", 0)->name,
                "remote_name" => trim($row->find("h1", 0)->plaintext)
            ];
        }

        foreach ($narrations as $narration) {
            $narration_row = DB::table("narrations_mappings")
                ->where("source_id", self::SOURCE_ID)
                ->where("remote_id", $narration->remote_id)
                ->first();

            if ($narration_row) {
                DB::table("narrations_mappings")
                    ->where("source_id", self::SOURCE_ID)
                    ->where("remote_id", $narration->remote_id)
                    ->update([
                        "remote_name" => $narration->remote_name
                    ]);
            } else {
                DB::table("narrations_mappings")->insert([
                    "source_id" => self::SOURCE_ID,
                    "remote_id" => $narration->remote_id,
                    "remote_name" => $narration->remote_name
                ]);
            }
        }
    }
}
