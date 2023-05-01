<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use KubAT\PhpSimple\HtmlDomParser;
use Illuminate\Support\Facades\DB;

class IslamwayScraperCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'islamway:scraper';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get recitations from Islamway Website';

    /**
     * The source ID
     *
     * @var int
     */
    const SOURCE_ID = 3;

    /**
     * The source Slug
     *
     * @var string
     */
    const SOURCE_SLUG = "islamway";

    function __construct()
    {
        parent::__construct();
        defined("MAX_FILE_SIZE") ?? define('MAX_FILE_SIZE', 600000000);
    }

    function checkTelwa($telawa, $page = 1, $id = false, $rank = false)
    {
        $link = "https://ar.islamway.net/recitations?media=audio&type=recitation&category=" . $telawa->remote_id . "&page=" . $page;

        if ($id) {
            $link .= "&lid=" . $id;
        }

        if ($rank) {
            $link .= "&lrank=" . urlencode($rank);
        }

        $this->info("checking: " . $link);

        $content = fetchUrl($link, "https://ar.islamway.net/", [
            "x-requested-with" => "XMLHttpRequest"
        ]);

        $html = HtmlDomParser::str_get_html($content);

        $colections_dom = $html->find(".iw-panel");

        $all_collections_scraped = false;

        if (count($colections_dom)) {
            foreach ($colections_dom as $colection_dom) {

                $data = $colection_dom->find(".collection", 0);

                if (!$data) {
                    continue;
                }

                $id = (int) $data->getAttribute("data-id");
                $rank = $data->getAttribute("data-rank");
                $collection_link = "https://ar.islamway.net" . $data->find(".media .media-body div[style=font-size:114%] a", 0)->href;
                $collection_id = (int) get_string_between($collection_link, "/collection/", "/");

                // check if collection already grabed
                $collection_row = DB::table("collections")
                    ->where("source_id", self::SOURCE_ID)
                    ->where("remote_id", $collection_id)
                    ->first();

                if ($collection_row) {
                    $all_collections_scraped = true;
                    break;
                }


                $reciter_dom = $data->find(".media .media-body h3 a", 0);

                if ($reciter_dom) {
                    $reciter_link = "https://ar.islamway.net" . $reciter_dom->href;
                    $reciter_name = trim($reciter_dom->plaintext);
                    $reciter_id = (int) get_string_between($reciter_link, "/scholar/", "/");
                } else {
                    $reciter_name = "مجموعة من القراء";
                    $reciter_id = 0;
                }


                $narration_dom = $data->find(".fragment-wpr .entry-footer a", 0);
                $narration_link = "https://ar.islamway.net" . $narration_dom->href;
                $narration_name = trim($narration_dom->plaintext);
                $narration_id = (int) get_string_between($narration_link, "/narration/", "/");

                // saving reciter if not exist

                $reciter_row = DB::table("reciters")
                    ->where("source_id", self::SOURCE_ID)
                    ->where("remote_id", $reciter_id)
                    ->first();

                if (!$reciter_row) {
                    DB::table("reciters")->insert([
                        "source_id" => self::SOURCE_ID,
                        "remote_id" => $reciter_id,
                        "remote_name" => $reciter_name
                    ]);
                    $reciter_row = DB::table("reciters")
                        ->where("source_id", self::SOURCE_ID)
                        ->where("remote_id", $reciter_id)
                        ->first();

                    $this->info("Reciter: " . $reciter_id);
                }

                // save the collection
                $collection_title = "مصحف " . $reciter_row->remote_name . " - " . $telawa->remote_name;
                $directory_name = $reciter_row->remote_name . "-" . $narration_name . "-" . $telawa->remote_name;

                $collection_row = DB::table("collections")
                    ->where("source_id", self::SOURCE_ID)
                    ->where("reciter_id", $reciter_row->id)
                    ->where("narration_id", $narration_id)
                    ->where("telawa_id", $telawa->id)
                    ->first();

                if (!$collection_row) {
                    DB::table("collections")->insert([
                        "source_id" => self::SOURCE_ID,
                        "remote_id" => $collection_id,
                        "reciter_id" => $reciter_row->id,
                        "narration_id" => $narration_id,
                        "telawa_id" => $telawa->id,
                        "directory_name" => $directory_name,
                        "url" => $collection_link,
                        "title" => $collection_title,
                    ]);

                    $collection_row = DB::table("collections")
                        ->where("source_id", self::SOURCE_ID)
                        ->where("reciter_id", $reciter_row->id)
                        ->where("narration_id", $narration_id)
                        ->where("telawa_id", $telawa->id)
                        ->first();

                    $this->line("Collection: " .  $collection_row->id);
                }

                $path = storage_path("quran/" . self::SOURCE_SLUG . "/" . $directory_name);

                if (!file_exists($path)) {
                    @mkdir($path, 0755, true);
                }

                $recitations = $this->getCollectionRecitations($collection_link);

                foreach ($recitations as $recitation) {
                    $file_path = $path . "/" . $recitation->sura . ".mp3";

                    if (!file_exists($file_path)) {
                        $mp3_content = fetchUrl($recitation->url, "https://ar.islamway.net/");
                        file_put_contents($file_path, $mp3_content);
                    }

                    $recitation_row = DB::table("recitations")
                        ->where("source_id", self::SOURCE_ID)
                        ->where("collection_id", $collection_row->id)
                        ->where("remote_id", $recitation->id)
                        ->first();

                    if (!$recitation_row) {

                        $this->line("Recitation: " .  $recitation->url);

                        DB::table("recitations")->insert([
                            "source_id" => self::SOURCE_ID,
                            "collection_id" => $collection_row->id,
                            "remote_id" => $recitation->id,
                            "title" => $recitation->title,
                            "sura" => $recitation->sura,
                            "file_name" => $recitation->file_name,
                            "url" => $recitation->url
                        ]);

                        $recitation_row = DB::table("recitations")
                            ->where("source_id", self::SOURCE_ID)
                            ->where("collection_id", $collection_row->id)
                            ->where("remote_id", $recitation->id)
                            ->first();
                    }
                }
            }

            if (!$all_collections_scraped) {
                $this->checkTelwa($telawa, $page + 1, $id, $rank);
            }
        }
    }

    protected function getCollectionRecitations($collection_link, $page = 1, $recitations = [])
    {
        $content = fetchUrl($collection_link . "?page=" . $page, "https://ar.islamway.net/", [
            "x-requested-with" => "XMLHttpRequest"
        ]);

        $html = HtmlDomParser::str_get_html($content);

        $recitations_dom = $html->find(".entry");

        if (count($recitations_dom)) {

            $rows = [];

            foreach ($recitations_dom as $recitation_dom) {
                $recitation_id = (int) $recitation_dom->getAttribute("data-id");
                $recitation_title = trim($recitation_dom->find(".col-md-7 a.title", 0)->plaintext);

                $link_dom = $recitation_dom->find(".entry-ctrls li", 4)?->find("a.icon-download[href]", 0);

                if (!$link_dom) {
                    $link_dom = $recitation_dom->find(".entry-ctrls li", 4)?->find(".dropdown .dropdown-menu li", 0)
                        ?->find("a", 0);
                }

                if (!$link_dom) {
                    continue;
                }

                $mp3_url = $link_dom->href;

                $url_parts = explode(".", basename($mp3_url));
                $sura_number = (int) $url_parts[0];

                $rows[] = (object) [
                    "id" => $recitation_id,
                    "title" => $recitation_title,
                    "url" => $mp3_url,
                    "sura" => $sura_number,
                    "file_name" => $sura_number . ".mp3"
                ];
            }

            $recitations = [
                ...$recitations,
                ...$rows
            ];

            return $this->getCollectionRecitations($collection_link, $page + 1, $recitations);
        }

        return $recitations;
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
        //$this->syncTelawat();

        $telawat = DB::table("telawat")->where("source_id", self::SOURCE_ID)->get();

        //   $this->checkTelwa($telawat[0], 2, 2575966, "2023-01-09 06:23:20");
        foreach ($telawat as $telawa) {
            $this->checkTelwa($telawa);
        }

        $this->info("update done");
    }

    /**
     * Sync Telawat
     */
    public function syncTelawat()
    {

        $this->info("checking Telawat..");

        $link = "https://ar.islamway.net/recitations?media=audio";

        $html = HtmlDomParser::str_get_html(file_get_contents($link));

        $types = [];

        foreach ($html->find("[data-caption=category] ul li div label") as $row) {
            $types[] = (object) [
                "source_id" => self::SOURCE_ID,
                "remote_id" => (int) $row->find("input", 0)->value,
                "remote_name" => trim($row->find("span", 0)->plaintext)
            ];
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

        $link = "https://ar.islamway.net/recitations?media=audio";

        $html = HtmlDomParser::str_get_html(file_get_contents($link));

        $narrations = [];

        foreach ($html->find("[data-caption=narration] ul li div label") as $row) {
            $narrations[] = (object) [
                "source_id" => self::SOURCE_ID,
                "remote_id" => (int) $row->find("input", 0)->value,
                "remote_name" => trim($row->find("span", 0)->plaintext)
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
