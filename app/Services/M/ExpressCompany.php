<?php

namespace App\Services\M;

class ExpressCompany extends \App\Services\BaseService
{

    protected ?string $connection = 'mysql_master';

    protected string $table = 'express_company';

    private array $map = [
        ["express_id"=>10000001,"expName"=>"其他"],
        ["express_id"=>10000002,"expName"=>"跑腿"],
        ["express_id"=>10000003,"expName"=>"库存"],
    ];

    public function channelMap(int|string $key):object
    {
        $value = null;
        foreach ($this->map as $obj){
            if(is_string($key)) {
                if($obj["expName"] === $key) {$value= $obj; break;}
            }else{
                if($obj["express_id"] === $key) {$value= $obj; break;}
            }
        }
        return (object)$value;
    }

    public function createChannel(array $values): int
    {
        $id = $this->get_unique_id();
        $values['express_id'] = $id;
        $this->tb->insert($values);
        return $id;
    }

    public function getChannel(int $id): object|null
    {
        $data = $this->tb->where('express_id', $id)->first();
        if(!$data){
            $data = $this->channelMap($id);
        }
        return $data;
    }

    public function getChannelInfo(int $id): object|null
    {
        $data = $this->tb->select('express_id','expName')->where('express_id', $id)->first();
        if(!$data){
            $data = $this->channelMap($id);
        }
        return $data;
    }

    public function getChannelByName(string $name): object|null
    {
        $data = $this->tb->where('simpleName', $name)->first();
        if(!$data){
            $data = $this->channelMap($name);
        }
        return $data;
    }

    public function getByExpName(string $name):object|null
    {
        $data = $this->tb->where('expName', $name)->first();
        if(!$data){
            $data = $this->channelMap($name);
        }
        return $data;
    }

    //获取渠道列表
    public function getChannelList(array $args): array
    {
        $page = $size = 0;
        extract($args);
        $tb = $this->tb;

        if(!empty($args["keyword"])){
            $tb = $tb->where("expName","like","%{$args["keyword"]}%");
        }
        $tb->whereNotNull("expName");

        $totalTb = clone $tb;
        $offset = max(0, ($page - 1) * $size);
        $list = $tb->select('express_id','expName')->orderByDesc("express_id")->offset($offset)->limit($size)->get()->toArray();
        $totalResult = $totalTb->selectRaw('COUNT(*) count')->first();
        $total = $totalResult->count ?? 0;

        return [$list, $total];
    }

}
