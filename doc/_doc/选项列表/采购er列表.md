32,99
[TOC]

##### 简要描述

- 采购列表

##### 请求URL

- ` 192.168.1.15:7880/api/tracking_order/user/purchasers `

##### 请求方式

- GET

##### 参数

| 参数名 | 必选 | 类型 | 说明 |
|:----|:---|:---|----|
| -   | -  | -  | -  |

##### 返回示例

```
{
    "code": 0,
    "data": {
        "tree": [
            {
                "id": 1020,
                "name": "Wei",
                "leader_user_id": null,
                "subordinates": []
            },
            {
                "id": 1021,
                "name": "Jun",
                "leader_user_id": null,
                "subordinates": []
            },
            {
                "id": 9999,
                "name": "Ting",
                "leader_user_id": null,
                "subordinates": []
            },
            {
                "id": 10004,
                "name": "Mr.T",
                "leader_user_id": null,
                "subordinates": []
            },
            {
                "id": 10010,
                "name": "Cao",
                "leader_user_id": null,
                "subordinates": []
            },
            {
                "id": 20015,
                "name": "Tao",
                "leader_user_id": null,
                "subordinates": []
            },
            {
                "id": 88880,
                "name": "Zhong",
                "leader_user_id": null,
                "subordinates": []
            },
            {
                "id": 88881,
                "name": "Huang",
                "leader_user_id": null,
                "subordinates": []
            },
            {
                "id": 100006,
                "name": "FeiFei",
                "leader_user_id": null,
                "subordinates": []
            },
            {
                "id": 50001,
                "name": "Hu",
                "leader_user_id": null,
                "subordinates": []
            },
            {
                "id": 50001,
                "name": "Hu",
                "leader_user_id": null,
                "subordinates": []
            },
            {
                "id": 100011,
                "name": "HuaHua",
                "leader_user_id": null,
                "subordinates": []
            },
            {
                "id": 100,
                "name": "Lan",
                "leader_user_id": null,
                "subordinates": [
                    {
                        "id": 2000,
                        "name": "Wen",
                        "leader_user_id": 100,
                        "subordinates": []
                    },
                    {
                        "id": 50003,
                        "name": "Lan Boy",
                        "leader_user_id": 100,
                        "subordinates": []
                    },
                    {
                        "id": 1000000022,
                        "name": "LiuJinHong",
                        "leader_user_id": 100,
                        "subordinates": []
                    }
                ]
            },
            {
                "id": 10015,
                "name": "Fei",
                "leader_user_id": null,
                "subordinates": []
            }
        ],
        "list": [
            {
                "id": 1020,
                "name": "Wei",
                "leader_user_id": null
            },
            {
                "id": 1021,
                "name": "Jun",
                "leader_user_id": null
            },
            {
                "id": 9999,
                "name": "Ting",
                "leader_user_id": null
            },
            {
                "id": 10004,
                "name": "Mr.T",
                "leader_user_id": null
            },
            {
                "id": 10010,
                "name": "Cao",
                "leader_user_id": null
            },
            {
                "id": 20015,
                "name": "Tao",
                "leader_user_id": null
            },
            {
                "id": 88880,
                "name": "Zhong",
                "leader_user_id": null
            },
            {
                "id": 88881,
                "name": "Huang",
                "leader_user_id": null
            },
            {
                "id": 100006,
                "name": "FeiFei",
                "leader_user_id": null
            },
            {
                "id": 50001,
                "name": "Hu",
                "leader_user_id": null
            },
            {
                "id": 50001,
                "name": "Hu",
                "leader_user_id": null
            },
            {
                "id": 100011,
                "name": "HuaHua",
                "leader_user_id": null
            },
            {
                "id": 100,
                "name": "Lan",
                "leader_user_id": null
            },
            {
                "id": 2000,
                "name": "-- Wen",
                "leader_user_id": 100
            },
            {
                "id": 50003,
                "name": "-- Lan Boy",
                "leader_user_id": 100
            },
            {
                "id": 1000000022,
                "name": "-- LiuJinHong",
                "leader_user_id": 100
            },
            {
                "id": 10015,
                "name": "Fei",
                "leader_user_id": null
            }
        ]
    },
    "time": 0.328
}
```

##### 返回参数说明

| 参数名 | 类型 | 说明 |
|:----|:---|----|
| -   | -  | -  |

##### 备注

- 更多返回错误代码请看首页的错误代码描述




