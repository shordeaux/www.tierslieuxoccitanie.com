<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use GuzzleHttp\Client;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\DomCrawler\Crawler;

class CrawlCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shordeaux:crawl';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crawl "old" www.tierslieuxoccitanie.com';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $client = new Client([
            'base_uri' => 'http://www.tierslieuxoccitanie.com',
            'timeout' => 2.0,
        ]);

        $results = array();

        $response = $client->request('GET', '/working_places');


        if (preg_match('/markers = handler.addMarkers\((.+)\);/', $response->getBody(), $tokens)) {
            $items = json_decode($tokens[1]);

            foreach ($items as $item) {
                $result = array(
                    'lat' => $item->lat,
                    'lng' => $item->lng,
                );
                if (preg_match('#<h3>(.+)</h3>#', $item->infowindow, $tokens)) {
                    $result['name'] = html_entity_decode($tokens[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
                    $result['slug'] = str_slug($result['name']);
                }
                if (isset($item->picture) && isset($item->picture->url)) {
                    $result['picture_small'] = $item->picture->url;
                }
                if (preg_match('#<p>\((.+)\) (.+)</p>#', $item->infowindow, $tokens)) {
                    $result['zip'] = $tokens[1];
                    $result['city'] = html_entity_decode($tokens[2], ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
                if (preg_match('#<p><i class="fa fa-link" aria-hidden="true"></i> <a target="_blank" href="(.+)">.+</a></p>#', $item->infowindow, $tokens)) {
                    $result['url'] = $tokens[1];
                }
                if (preg_match('#<p><i class="fa fa-phone" aria-hidden="true"></i> (.+) </p>#', $item->infowindow, $tokens)) {
                    $result['phone'] = $tokens[1];
                }
                if (preg_match('#<p><i class="fa fa-envelope-o" aria-hidden="true"></i> (.+) </p>#', $item->infowindow, $tokens)) {
                    $result['email'] = $tokens[1];
                }
                if (preg_match('#<a target="blank" class="btn btn-default department" href="/working_places/(.+)">Voir</a>#', $item->infowindow, $tokens)) {
                    $result['member_id'] = $tokens[1];
                    try {

                        $response = $client->request('GET', '/working_places/' . $result['member_id']);
                        $crawler = new Crawler($response->getBody()->getContents());

                        $result['picture_large'] = $crawler->filter('div.slider > img')->attr('src');
                        file_put_contents(sprintf('public/images/%s.jpg', $result['slug']), file_get_contents($result['picture_large']));

                        $result['adress'] = trim($crawler->filter('.card_work .adress div')->eq(0)->text());
                        //        $result['zip'] = trim($crawler->filter('.card_work .adress div')->eq(1)->text());
                        //        $result['city'] = trim($crawler->filter('.card_work .adress div')->eq(2)->text());
                        $result['description'] = trim($crawler->filter('.work-content .introduction')->text());

                        $result['manager'] = trim($crawler->filter('.manager h4.name')->text());
                    } catch (\GuzzleHttp\Exception\ServerException $e) {
                        $this->error(sprintf('Error while fetching details for %s', $result['name']));
                    }

                }
                $results[] = $result;
            }
        }

       // print_r($results);

        Excel::create('tierslieuxoccitanie', function ($excel) use ($results) {
            $excel->sheet('Export', function ($sheet) use ($results) {
                $datas = array(array('ID', 'Nom', 'Lat', 'Lng', 'Adresse', 'Code postal', 'Ville', 'URL', 'Téléphone', 'Email', 'Photo', 'Contact', 'Description'));
                foreach ($results as $item) {
                    $data = array();
                    $data[] = @$item['member_id'];
                    $data[] = @$item['name'];
                    $data[] = @$item['lat'];
                    $data[] = @$item['lng'];
                    $data[] = @$item['adress'];
                    $data[] = @$item['zip'];
                    $data[] = @$item['city'];
                    $data[] = @$item['url'];
                    $data[] = @$item['phone'];
                    $data[] = @$item['email'];
                    $data[] = @$item['picture_large'];
                    $data[] = @$item['manager'];
                    $data[] = @$item['description'];
                    $datas[] = $data;
                }
                //print_r($datas);
                $sheet->with($datas);

            });

        })->store('xlsx');

    }
}
