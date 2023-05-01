<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use KubAT\PhpSimple\HtmlDomParser;
use Illuminate\Support\Facades\DB;

class MidadScraperCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'midad:scraper';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get recitations from Midad Website';

    /**
     * The source ID
     *
     * @var int
     */
    const SOURCE_ID = 1;

    /**
     * The source Slug
     *
     * @var int
     */
    const SOURCE_SLUG = "midad";

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        // Getting All links and store it in database

        $this->info("checking new collections");

        $links = $this->getNewLinks();

        if (count($links) == 0) {
            return $this->info("update done");
        }

        foreach ($links as $link) {

            $collection_id = $this->getCollectionId($link);

            $html = HtmlDomParser::str_get_html(file_get_contents($link));

            // reciter
            $collection_title = trim($html->find(".audio-title h1", 0)->plaintext);

            $reciter_dom = $html->find(".audio-title .scholars a", 0);
            $reciter_name = trim($reciter_dom->plaintext);
            $reciter_id = $this->getReciterId($reciter_dom->href);

            $reciter = DB::table("reciters")
                ->where("source_id", self::SOURCE_ID)
                ->where("remote_id", $reciter_id)
                ->first();

            if (!$reciter) {
                DB::table("reciters")->insert([
                    "source_id" => self::SOURCE_ID,
                    "remote_id" => $reciter_id,
                    "remote_name" => $reciter_name,
                ]);

                $reciter = DB::table("reciters")
                    ->where("source_id", self::SOURCE_ID)
                    ->where("remote_id", $reciter_id)
                    ->first();

                $this->info("Reciter: ". $reciter->id);
            }

            // mushaf types

            $mushaf_type_dom = $html->find(".audio-series-wrap div", 3)->find("a", 0);
            $telawa_id = $this->getTelawaId($mushaf_type_dom->href);
            $telawa = DB::table("telawat")
                ->where("source_id", self::SOURCE_ID)
                ->where("remote_id", $telawa_id)
                ->first();

            // narrations

            $narration_dom = $html->find(".audio-series-wrap div", 4)->find("a", 0);
            $remote_id = $this->getNarrationId($narration_dom->href);
            $narration = DB::table("narrations_mappings")
                ->where("source_id", self::SOURCE_ID)
                ->where("remote_id", $remote_id)
                ->first();

            // save recitations
            $directory_name = $reciter->remote_name . "-" . $narration->remote_name . "-" . $telawa->remote_name;
            $path = storage_path("quran/" . self::SOURCE_SLUG . "/" . $directory_name);

            DB::table("collections")->insert([
                "source_id" => self::SOURCE_ID,
                "remote_id" => $collection_id,
                "title" => $collection_title,
                "reciter_id" => $reciter_id,
                "narration_id" => $narration->id,
                "telawa_id" => $telawa->id,
                "directory_name" => $directory_name,
                "url" => $link
            ]);

            $collection = DB::table("collections")
                ->where("source_id", self::SOURCE_ID)
                ->where("remote_id", $collection_id)
                ->first();

            $this->line("Collection: ". $collection->id);

            if (!file_exists($path)) {
                @mkdir($path, 0755, true);
            }

            // get collection recitations

            foreach ($html->find(".series-listing li a") as $a_tag) {

                $link = $a_tag->href;

                $item_title = $a_tag->find(".item-title", 0);

                $item_title->find("span", 0)->innertext = "";

                $recitation_id = $this->getRecitationId($link);
                $recitation_title = trim($item_title->plaintext);

                $recitation = DB::table("recitations")
                    ->where("source_id", self::SOURCE_ID)
                    ->where("remote_id", $recitation_id)
                    ->first();

                if ($recitation) {
                    continue;
                }

                // Getting the mp3

                $html = HtmlDomParser::str_get_html(file_get_contents($link));

                $download1_link = $html->find(".btn-zoomsounds.btn-download", 0);
                $download2_link = $html->find(".download-file a", 0);

                $mp3_url = false;

                if ($download1_link) {
                    $mp3_url = $download1_link->href;
                }

                if ($download2_link) {
                    $mp3_url = $download2_link->href;
                }

                if (!$mp3_url) {
                    continue;
                }

                $mp3_url = str_replace("&amp;", "&", $mp3_url);

                $url_parts = explode(".", basename($mp3_url));
                $sura_number = (int) $url_parts[0];

                $this->line("Recitation: " . $mp3_url);

                $mp3_content = fetchUrl($mp3_url, "https://midad.com/");

                file_put_contents($path . "/" . $sura_number . ".mp3", $mp3_content);

                // save recitation

                DB::table("recitations")->insert([
                    "source_id" => self::SOURCE_ID,
                    "remote_id" => $recitation_id,
                    "collection_id" => $collection->id,
                    "title" => $recitation_title,
                    "sura" => $sura_number,
                    "file_name" => $sura_number . ".mp3",
                    "url" => $link
                ]);
            }
        }

        return $this->handle();
    }


    /**
     * Getting narration id from narration url
     * @param string $link
     */
    protected function getNarrationId($link)
    {
        $link = str_replace("https://midad.com/recitations/narration/", "", $link);
        $path_parts = explode("/", $link);

        return (int) $path_parts[0];
    }

    /**
     * Getting telawa id from telawa type url
     * @param string $link
     */
    protected function getTelawaId($link)
    {
        $num = str_replace("https://midad.com/recitations/mushaf-types/", "", $link);

        return (int) $num;
    }

    /**
     * Getting reciter id from reciter url
     * @param string $link
     */
    protected function getReciterId($link)
    {
        $link = str_replace("https://midad.com/scholar/", "", $link);
        $path_parts = explode("/", $link);

        return (int) $path_parts[0];
    }

    /**
     * Getting collection id from collection url
     * @param string $link
     */
    protected function getCollectionId($link)
    {
        $link = str_replace("https://midad.com/collection/", "", $link);
        $path_parts = explode("/", $link);

        return (int) $path_parts[0];
    }

    /**
     * Getting recitation id from recitation url
     * @param string $link
     */
    protected function getRecitationId($link)
    {
        $link = str_replace("https://midad.com/recitation/", "", $link);
        $path_parts = explode("/", $link);

        return (int) $path_parts[0];
    }

    /**
     * Getting collections links
     * @param string $link
     */
    protected function getNewLinks()
    {
        $page = 1;
        $links = [];

        while ($page >= 1) {

            $url = "https://midad.com/recitations/new/collections?sort-by=newest&search=&scholar-search=&narration=all&page=" . $page;

            $content = file_get_contents($url);
            $html = HtmlDomParser::str_get_html($content);

            $a_tags = $html->find(".series-main header h3 a");

            if (count($a_tags) == 0) {
                break;
            }

            $is_end = false;

            foreach ($a_tags as $a) {
                $collection = DB::table("collections")
                    ->where("source_id", self::SOURCE_ID)
                    ->where("url", $a->href)
                    ->first();

                if ($collection) {
                    $is_end = true;
                    break;
                }

                $links[] = $a->href;
            }

            if ($is_end) {
                break;
            }

            $page++;
        }

        return array_unique($links);
    }
}
