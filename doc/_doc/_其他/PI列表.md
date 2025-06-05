1,99
[TOC]

##### 简要描述

- PI列表

##### 请求URL

- ` 192.168.1.15:7880/api/tracking_order/pi/list `

##### 请求方式

- GET

##### 参数

| 参数名           | 必选 | 类型     | 说明        |
|:--------------|:---|:-------|-----------|
| page          | 是  | int    | 页码，默认：1   |
| size          | 是  | int    | 页大小，默认：20 |
| keyword       | 否  | string | 搜索词       |
| purchaser_id  | 否  | int    | 采购ID      |
| country_id    | 否  | int    | 国家ID      |
| submit_status | 否  | int    | 提交状态      |

##### 返回示例

```json
{
    "code": 0,
    "data": {
        "total": 44,
        "list": [
            {
                "order_id": 23365,
                "PI_name": "AL2205197698",
                "Sales_User_ID": 99,
                "sales": {
                    "id": 99,
                    "name": "Cathy2022"
                },
                "items": [
                    {
                        "order_info_id": 60106,
                        "quantity": 4,
                        "product_name_pi": "6ES7214-1AG40-0XB0",
                        "shipTasks": [
                            {
                                "ShioTask_item_id": 18776,
                                "Shiptask_id": 7810,
                                "products_Name": "6ES7214-1AG40-0XB0",
                                "Qtynumber": 2,
                                "shiptask": {
                                    "Shiptask_id": 7810,
                                    "Shiptask_name": "S96202303086605",
                                    "shipping_way": null,
                                    "Shiptask_delivery_Singlenumber": "222222222222",
                                    "Shitask_turn_delivery_Singlenumber": "33333333333"
                                }
                            }
                        ],
                        "pods": [
                            {
                                "Purchaseorder_detailed_id": 24168,
                                "Purchaseorder_id": 15522,
                                "products_Name": "6ES7214-1AG40-0XB0",
                                "Qtynumber": 4,
                                "Purchaser_id": 51,
                                "create_user": 51,
                                "purchaser": {
                                    "id": 51,
                                    "name": "liu"
                                },
                                "purchaseOrder": {
                                    "purchaseorder_id": 15522,
                                    "create_user": 51,
                                    "Purchaseordername": "TY2205204632"
                                },
                                "shipTasks": [
                                    {
                                        "ShioTask_item_id": 18776,
                                        "Shiptask_id": 7810,
                                        "products_Name": "6ES7214-1AG40-0XB0",
                                        "Qtynumber": 2,
                                        "shiptask": {
                                            "Shiptask_id": 7810,
                                            "Shiptask_name": "S96202303086605",
                                            "shipping_way": null,
                                            "Shiptask_delivery_Singlenumber": "222222222222",
                                            "Shitask_turn_delivery_Singlenumber": "33333333333"
                                        }
                                    }
                                ],
                                "simple_submit": [
                                    {
                                        "submit_status_txt": "预交",
                                        "submit_status": 3,
                                        "quantity": 1
                                    }
                                ]
                            }
                        ]
                    },
                    {
                        "order_info_id": 60107,
                        "quantity": 20,
                        "product_name_pi": "CJ1W-CT021",
                        "shipTasks": [
                            {
                                "ShioTask_item_id": 18777,
                                "Shiptask_id": 7810,
                                "products_Name": "CJ1W-CT021",
                                "Qtynumber": 20,
                                "shiptask": {
                                    "Shiptask_id": 7810,
                                    "Shiptask_name": "S96202303086605",
                                    "shipping_way": null,
                                    "Shiptask_delivery_Singlenumber": "222222222222",
                                    "Shitask_turn_delivery_Singlenumber": "33333333333"
                                }
                            }
                        ],
                        "pods": [
                            {
                                "Purchaseorder_detailed_id": 24173,
                                "Purchaseorder_id": 15526,
                                "products_Name": "CJ1W-CT021",
                                "Qtynumber": 20,
                                "Purchaser_id": 2000,
                                "create_user": 2000,
                                "purchaser": {
                                    "id": 2000,
                                    "name": "Wen"
                                },
                                "purchaseOrder": {
                                    "purchaseorder_id": 15526,
                                    "create_user": 2000,
                                    "Purchaseordername": "XY2205204573"
                                },
                                "shipTasks": [
                                    {
                                        "ShioTask_item_id": 18777,
                                        "Shiptask_id": 7810,
                                        "products_Name": "CJ1W-CT021",
                                        "Qtynumber": 20,
                                        "shiptask": {
                                            "Shiptask_id": 7810,
                                            "Shiptask_name": "S96202303086605",
                                            "shipping_way": null,
                                            "Shiptask_delivery_Singlenumber": "222222222222",
                                            "Shitask_turn_delivery_Singlenumber": "33333333333"
                                        }
                                    }
                                ],
                                "simple_submit": []
                            }
                        ]
                    }
                ]
            }
        ]
    },
    "time": 0.68
}
```

##### 返回参数说明

| 参数名 | 类型 | 说明 |
|:----|:---|----|
| -   | -  | -  |

##### 备注

- 更多返回错误代码请看首页的错误代码描述




