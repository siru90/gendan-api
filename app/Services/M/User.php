<?php

namespace App\Services\M;
use Illuminate\Support\Facades\DB;

class User extends \App\Services\BaseService
{

    protected ?string $connection = 'mysql_master';

    protected string $table = 'user';

    public function login($username, $password): object|bool
    {
        $row = $this->tb->where('name', $username)->where('enable', 1)->first();
        if (!$row) return false;
        if (strtoupper($row->password) == strtoupper(md5($password))) {
            #特殊业务处理
            if($row->id == 1000000084){
                $row->user_group_id = 9;
            }
            return $row;
        }
        return false;
    }


    public function getUserInfo($id): object|null
    {
        /*
        $data = $this->tb->select('id', 'name','user_group_id','Tel')->where('id', $id)->where('enable', 1)->first();
        if(!empty($data)){
            $data->profile_photo = "";  #用户头像
            $data->user_mail = "";  #用户邮箱
        }
        */

        $data = \Illuminate\Support\Facades\Redis::command("get", ["gd_getuserinfo_".$id]);
        $data = json_decode($data);
        if(empty($data)){
            $data = $this->tb->select('id', 'name','user_group_id','Tel')->where('id', $id)->where('enable', 1)->first();
            if(!empty($data)){
                $data->profile_photo = "";  #用户头像
                $data->user_mail = "";  #用户邮箱

                #特殊业务处理
                if($data->id == 1000000084){
                    $data->user_group_id = 9;
                }
            }
            \Illuminate\Support\Facades\Redis::command("set", ["gd_getuserinfo_".$id, json_encode($data), ['EX' => 3600 * 24]]);
        }

        return $data;
    }

    public function getUserByIds($ids):array
    {
        return $this->tb->select('id', 'name','user_group_id','Tel')->whereIn('id', $ids)->where('enable', 1)->get()->toArray();
    }

    /*
     * 判断是否是管理员
     * [9,11,98,99,103] 这些属于管理员
     *
        1.  user_group_id,9是管理员，11财务，98跟单员，99打包员，103销售跟单,属于总务,198总务跟单,199总务核对，可以看全部
        2. 销售，user_group_id 为1，只能看自己的
        3. 采购 ，user_group_id 为3，可以看自己和团队及所有子团队
        4.其他美工，技术等...  只能看自己的
     * */
    public function isNotAdmin($id):array|bool
    {
        $res = array();
        $user = $this->tb->select('id','user_group_id')->where('id', $id)->where('enable', 1)->first();
        #特殊业务处理
        if($user->id == 1000000084){
            $user->user_group_id = 9;
        }
        if(!in_array($user->user_group_id, [9,98,99,198,199])){
            $res[] = $user->id;
            if(in_array($user->user_group_id, [3])){  //[3,1]
                 # 递归查整个团队，
                [$ids, ,] = UserRelation::getInstance()->getSubordinates($user->id);
                //$supIds = UserRelation::getInstance()->getSupordinates($user->id);
                //$ids = array_unique(array_merge($ids,$supIds));
                $ids = array_unique(array_merge($res,$ids));
                return $ids;
            }
            if($user->user_group_id == 103){
                $supIds = UserRelation::getInstance()->getSuperior($user->id);
                $ids = array_unique(array_merge($res,$supIds));
                return $ids;
            }
            return $res;
        }
        return false;
    }

    //根据用户组获取用户
    public function getUserByGroupId($groupId):array
    {
        return $this->tb->select('id', 'name')->where("user_group_id",$groupId)->where('enable', 1)->orderByDesc("id")->get()->toArray(); //1销售，3采购
    }

    public function fillUsers($objs, $from, $to = 'user'): void
    {
        $ids = [];
        foreach ($objs as $obj) {
            $ids[] = $obj->$from;
        }
        if (!count($ids)) return;
        $ids = array_values(array_unique($ids));
        $users = $this->tb->select('id', 'name')->whereIn('id', $ids)->get()->toArray();
        $userMap = [];
        foreach ($users as $user) {
            $userMap[$user->id] = $user;
        }

        foreach ($objs as $obj) {
            if (isset($userMap[$obj->$from])) {
                $obj->$to = $userMap[$obj->$from];
            }
        }
    }



}
