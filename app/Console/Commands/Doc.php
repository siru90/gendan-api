<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class Doc extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:doc';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        # 文档更新到部署的showdoc（代码在doc目录下）
        $api = 'http://192.168.1.15:4989/server/index.php?s=/api/item/updateByApi';
        $api_key = 'ba1f3bbfa24eb40f68d7c3d7eb7da1da1355682579';
        $api_token = 'a7f8b0185ca0d6464c337086f69c57be28097559';

        $client = new \GuzzleHttp\Client([
            \GuzzleHttp\RequestOptions::TIMEOUT => 5.0,
            \GuzzleHttp\RequestOptions::HEADERS => [
                'Content-Type' => 'application/json',
            ],
        ]);


        if (!chdir('doc/_doc')) {
            return;
        }

        $okMap = [];

        $list = $this->scanDir('.');
        foreach ($list as $filename) {
            $cat_name = dirname($filename);
            if ($cat_name === '.') $cat_name = '';
            if (str_starts_with($cat_name, './')) $cat_name = substr($cat_name, 2);

            $page_title = basename($filename, '.md');

            $page_content0 = $page_content = file_get_contents($filename);

            $pageId0 = '';
            $number = 99;

            $line1 = $this->getFirstLine($filename);
            if (preg_match('/^(\d+),\s*(\d+)$/mu', $line1, $match)) {
                [, $pageId0, $number] = $match;
                if (isset($okMap["$pageId0, "])) {
                    echo "Error: ID存在重复：" . " $page_title $pageId0, $number   \n";
                    continue;
                }
                $okMap["$pageId0, "] = 1;
                $page_content0 = substr($page_content, strlen($line1));
            }

            $response = $client->post($api, [
                \GuzzleHttp\RequestOptions::BODY => json_encode([
                    'api_key' => $api_key,
                    'api_token' => $api_token,
                    'cat_name' => $cat_name,
                    'page_title' => $page_title,
                    'page_content' => $page_content0,
                    's_number' => $number,
                    'oo_page_id' => $pageId0,
                ]),
            ]);

            $json = $response->getBody()->getContents();
            $json = json_decode($json);

            if (isset($json->error_code) && !$json->error_code) {
                $pageId = $json->data->page_id;
                if ($pageId) {
                    if ($page_content) {
                        if ($pageId != $pageId0) {
                            file_put_contents($filename, sprintf("%s,%s\n%s", $pageId, $number, $page_content0));
                        }
                    }
                }
            }
        }
    }


    private function scanDir($dir): \Generator
    {
        $list = scandir($dir);
        foreach ($list as $filename) {
            if ($filename === '.' || $filename === '..') continue;
            if ($filename === '.git') continue;
            $aDir = sprintf("%s/%s", $dir, $filename);
            if (is_dir($aDir)) {
                foreach ($this->scanDir($aDir) as $_filename) {
                    yield $_filename;
                }
            } else {
                yield $aDir;
            }
        }
    }

    /**
     * @param string $filename
     * @return string
     */
    private function getFirstLine(string $filename): string
    {
        $stream = fopen($filename, "r");
        $line1 = fgets($stream);
        $line1 = trim($line1);
        fclose($stream);
        return $line1;
    }
}
