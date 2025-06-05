<?php
namespace App\Services;

class AliDeliver extends BaseService
{
    //快递物流节点跟踪:对接阿里云接口
    public function aliDeliverShowapi(array $validated): array|null
    {
        $com = $nu = $receiver_phone = $sender_phone = "";
        extract($validated);

        $host = "https://ali-deliver.showapi.com";
        $path = "/showapi_expInfo";
        $appcode = "82f33bd0842945ca90247a57ecebb1c0";
        $headers = array();
        array_push($headers, "Authorization:APPCODE " . $appcode);
        $querys = "com={$com}&nu={$nu}&receiverPhone={$receiver_phone}&senderPhone={$sender_phone}";
        $url = $host . $path . "?" . $querys;

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_FAILONERROR, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false); //用false，不返回响应头信息，只返回json数据
        if (1 == strpos("$".$host, "https://"))
        {
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        }
        $res = curl_exec($curl);
        $data= json_decode($res,true);

        return $data;
    }


    /*
    //返回正常示例
    {
        "update": "更新时间戳",
        "upgrade_info": "提示信息，用于提醒用户可能出现的情况",
        "updateStr": "更新时间",
        "logo": "快递公司logo",
        "dataSize": "数据节点的长度",
        "status": "快递状态 1 暂无记录 2 在途中 3 派送中 4 已签收 (完结状态) 5 用户拒签 6 疑难件 7 无效单 (完结状态) 8 超时单 9 签收失败 10 退回",
        "fee_num": "计费次数。例如：0为计费0次，即不计费；1为计费1次",
        "tel": "快递公司联系方式",
        "data": "在途跟踪数据",
        "-  time": "物流跟踪发生的时间",
        "-  context": "物流跟踪信息",
        "expSpellName": "快递编码",
        "msg": "返回提示信息",
        "mailNo": "快递单号",
        "queryTimes": "无走件记录时被查询次数 注意：在24小时内，查询次数>10次将会计费",
        "ret_code": "0 查询成功 或 提交成功。 1 输入参数错误。 2 查不到物流信息。 3 单号不符合规则。 4 快递公司编码不符合规则。 5 快递查询渠道异常。 6 auto时未查到单号对应的快递公司,请指定快递公司编码。 7 单号与手机号不匹配 其他参数：接口调用失败",
        "flag": "true：查询成功，表示ret_code=0且data的长度>0。可使用本字段做是否读取data列表的依据。 false：查询失败。",
        "expTextName": "快递简称",
        "possibleExpList": "自动识别结果"
    }
------------------
    示例
    {
      "showapi_res_error": "",
      "showapi_fee_num": 1,
      "showapi_res_code": 0,
      "showapi_res_id": "628ed5cc0de3769f067c7806",
      "showapi_res_body": {
        "update": 1653528013043,
        "upgrade_info": "",
        "updateStr": "2022-05-26 09:20:13",
        "logo": "http://static.showapi.com/app2/img/expImg/yuantong.jpg",
        "dataSize": 11,
        "status": 4,
        "fee_num": 1,
        "tel": "021-69777888/95554",
        "data": [
          {
            "time": "2022-05-19 20:25:29",
            "context": "客户签收人: 已签收，签收人凭取货码签收。 已签收  感谢使用圆通速递，期待再次为您服务 如有疑问请联系：13*******11，投诉电话：13*******11 疫情期间圆通每天对网点多次消毒，快递小哥新冠疫苗已接种，每天测量体温，佩戴口罩"
          },
        ],
        "expSpellName": "yuantong",
        "msg": "查询成功",
        "mailNo": "YT6493188734653",
        "queryTimes": 1,
        "ret_code": 0,
        "flag": true,
        "expTextName": "圆通速递",
        "possibleExpList": []
      }
    }

    */
}
?>
