<?php

namespace App\Ok;

class Search
{
    use \App\Utils\GetInstances;

    //无用
    const INDEX_EXP = 'gd_exp_index';
    const INDEX_PIS = 'gd_pis_index';
    const INDEX_POS = 'gd_pos_index';
    const INDEX_SOS = 'gd_sos_index';

    //外部系统product
    const INDEX_PRODUCT = 'gd_ihu_product_search_model_01';

    //内部系统product
    const INDEX_PRODUCT_INTERNAL = 'internal_product_search_model_02';


    public function getClient(): \Elasticsearch\Client
    {
        $client = \Elasticsearch\ClientBuilder::create();
        return $client->setHosts([\env('ELASTICSEARCH_HOST'),])
            ->setBasicAuthentication(\env('ELASTICSEARCH_USER'), \env('ELASTICSEARCH_PASS'))
            ->setSSLVerification(false)
            ->build();
    }

    public function getClientUS(): \Elasticsearch\Client
    {
        $client = \Elasticsearch\ClientBuilder::create();
        return $client->setHosts([\env('ELASTICSEARCH_HOST_US'),])
            ->setBasicAuthentication(\env('ELASTICSEARCH_USER_US'), \env('ELASTICSEARCH_PASS_US'))
            ->setSSLVerification(false)
            ->build();
    }

    public function getClientDE(): \Elasticsearch\Client
    {
        $client = \Elasticsearch\ClientBuilder::create();
        return $client->setHosts([\env('ELASTICSEARCH_HOST_DE'),])
            ->setBasicAuthentication(\env('ELASTICSEARCH_USER_DE'), \env('ELASTICSEARCH_PASS_DE'))
            ->setSSLVerification(false)
            ->build();
    }

    #巴西es服务器
    public function getClientBX(): \Elasticsearch\Client
    {
        $client = \Elasticsearch\ClientBuilder::create();
        return $client->setHosts([\env('ELASTICSEARCH_HOST_BR'),])
            ->setBasicAuthentication(\env('ELASTICSEARCH_USER_BR'), \env('ELASTICSEARCH_PASS_BR'))
            ->setSSLVerification(false)
            ->build();
    }

    #新加坡
    public function getClientXJP(): \Elasticsearch\Client
    {
        $client = \Elasticsearch\ClientBuilder::create();
        return $client->setHosts([\env('ELASTICSEARCH_HOST_SG'),])
            ->setBasicAuthentication(\env('ELASTICSEARCH_USER_SG'), \env('ELASTICSEARCH_PASS_SG'))
            ->setSSLVerification(false)
            ->build();
    }

    #美东
    public function getClientUSE(): \Elasticsearch\Client
    {
        $client = \Elasticsearch\ClientBuilder::create();
        return $client->setHosts([\env('ELASTICSEARCH_HOST_USE'),])
            ->setBasicAuthentication(\env('ELASTICSEARCH_USER_USE'), \env('ELASTICSEARCH_PASS_USE'))
            ->setSSLVerification(false)
            ->build();
    }

    public function clear(): void
    {
        $index = self::INDEX_EXP;
        $client = \App\Ok\Search::getInstance()->getClient();
        $client->deleteByQuery([
            'index' => $index,
            'body' => [
                'query' => [
                    'query_string' => [
                        'query' => "*:*",
                    ],
                ],
            ],
        ]);
    }
}
