<?php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;


class ProductIndexMappingsUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     * 同步外部系统产品表到ES
     */
    public function handle(): void
    {
        Log::channel('sync')->info('ES ProductIndexMappingsUpdate  handle() ---:' );
        //$this->updateMappings(ProductIndexUpdate::LAST_KEY);

        //$this->forceMerge();
        \App\Jobs\ProductIndexUpdate::dispatch();
    }

    private function updateMappings(string $lastKey):void
    {
        if (!\App\Ok\Locker::lock("gd.update.product.index.properties.v1", 3600 * 24 * 7)) return;

        $index = \App\Ok\Search::INDEX_PRODUCT;
        $client = \App\Ok\Search::getInstance()->getClient();
        $clientUS = \App\Ok\Search::getInstance()->getClientUS();
        $clientDE = \App\Ok\Search::getInstance()->getClientDE();

        Log::channel('sync')->info('ES ProductIndexMappingsUpdate  $client:' .json_encode($client));
        Log::channel('sync')->info('ES ProductIndexMappingsUpdate  $clientUS:' .json_encode($clientUS));
        Log::channel('sync')->info('ES ProductIndexMappingsUpdate  $clientDE:' .json_encode($clientDE));

        try {
            $params = ['index' => $index];
            $settings = $client->indices()->delete($params);
            $settingsUS = $clientUS->indices()->delete($params);
            $settingsDE = $clientDE->indices()->delete($params);
            Log::channel('sync')->info('ES updateMappings() delete $setting:'.json_encode($settings));
            Log::channel('sync')->info('ES updateMappings() delete $settingsUS:'.json_encode($settingsUS));
            Log::channel('sync')->info('ES updateMappings() delete $settingsDE:'.json_encode($settingsDE));
        } catch (\Exception $e) {
            echo $e->getMessage(), "\n";
            Log::channel('sync')->info('ES updateMappings() Exception:'.json_encode( $e->getMessage().'file:'.$e->getFile().$e->getLine()) );
        }


        $params = [
            'index' => $index,
            'body' => [
                'settings' => [
                    'index' => [
                        'max_ngram_diff' => 3,
                    ],
                    'analysis' => [
                        'analyzer' => [
                            'ng1_analyzer' => [
                                'tokenizer' => 'ng1_tokenizer',
                                'filter' => ['lowercase'],
                            ],
                            'ng2_analyzer' => [
                                'tokenizer' => 'ng2_tokenizer',
                                'filter' => ['lowercase'],
                            ],
                            'ng3_analyzer' => [
                                'tokenizer' => 'ng3_tokenizer',
                                'filter' => ['lowercase'],
                            ],
                            'ng4_analyzer' => [
                                'tokenizer' => 'ng4_tokenizer',
                                'filter' => ['lowercase'],
                            ],
                            'ng5_analyzer' => [
                                'tokenizer' => 'ng5_tokenizer',
                                'filter' => ['lowercase'],
                            ],
                        ],
                        'tokenizer' => [
                            'ng1_tokenizer' => [
                                'type' => 'ngram',
                                'min_gram' => 1,
                                'max_gram' => 1,
                            ],
                            'ng2_tokenizer' => [
                                'type' => 'ngram',
                                'min_gram' => 2,
                                'max_gram' => 2,
                            ],
                            'ng3_tokenizer' => [
                                'type' => 'ngram',
                                'min_gram' => 3,
                                'max_gram' => 3,
                            ],
                            'ng4_tokenizer' => [
                                'type' => 'ngram',
                                'min_gram' => 4,
                                'max_gram' => 4,
                            ],
                            'ng5_tokenizer' => [
                                'type' => 'ngram',
                                'min_gram' => 5,
                                'max_gram' => 5,
                            ],
                        ],
                    ],
                    'number_of_shards' => 1,
                    'number_of_replicas' => 2,
                ],
                'mappings' => [
                    'properties' => [
                        'product_id' => ['type' => 'long',],
                        'model' => [
                            'type' => 'text',
                            'analyzer' => 'ng1_analyzer',
                            'fields' => [
                                'n2' => ['type' => 'text', 'analyzer' => 'ng2_analyzer',],
                                'n3' => ['type' => 'text', 'analyzer' => 'ng3_analyzer',],
                                'n4' => ['type' => 'text', 'analyzer' => 'ng4_analyzer',],
                                'n5' => ['type' => 'text', 'analyzer' => 'ng5_analyzer',],
                            ],
                        ],
                        'product_name' => [
                            'type' => 'text',
                            'analyzer' => 'ng1_analyzer',
                            'fields' => [
                                'n2' => ['type' => 'text', 'analyzer' => 'ng2_analyzer',],
                                'n3' => ['type' => 'text', 'analyzer' => 'ng3_analyzer',],
                                'n4' => ['type' => 'text', 'analyzer' => 'ng4_analyzer',],
                                'n5' => ['type' => 'text', 'analyzer' => 'ng5_analyzer',],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        try {
            $settings = $client->indices()->create($params);
            $settingsUS = $clientUS->indices()->create($params);
            $settingsDE = $clientDE->indices()->create($params);

            Log::channel('sync')->info('ES ProductIndexMappingsUpdate() create $setting:'.json_encode($settings));
            Log::channel('sync')->info('ES ProductIndexMappingsUpdate() create $settingsUS:'.json_encode($settingsUS));
            Log::channel('sync')->info('ES ProductIndexMappingsUpdate() create $settingsDE:'.json_encode($settingsDE));
        } catch (\Exception $e) {
            echo $e->getMessage(), "\n";
            Log::channel('sync')->info('ES ProductIndexMappingsUpdate() Exception:'.json_encode( $e->getMessage().'file:'.$e->getFile().$e->getLine()) );
        }

        \Illuminate\Support\Facades\Redis::command("del", [$lastKey]);
    }

    private function forceMerge(): void
    {
        $client = \App\Ok\Search::getInstance()->getClient();

        $params = ['index' => \App\Ok\Search::INDEX_PRODUCT];
        $response = $client->indices()->forcemerge($params);
        Log::channel('sync')->info('ES forceMerge() $response = '.json_encode($response));
        //var_dump($response);
    }

}
?>
