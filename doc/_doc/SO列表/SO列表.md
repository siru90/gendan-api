3,99
[TOC]

##### 简要描述

- SO列表

##### 请求URL

- ` 192.168.1.15:7880/api/tracking_order/so/list `

##### 请求方式

- GET

##### 参数

| 参数名           | 必选 | 类型     | 说明        |
|:--------------|:---|:-------|-----------|
| page          | 是  | int    | 页码，默认：1   |
| size          | 是  | int    | 页大小，默认：20 |
| keyword       | 否  | string | 搜索词       |
| purchaser_id  | 否  | int    | 采购ID      |
| sales_id      | 否  | int    | 销售ID      |
| submit_status | 否  | int    | 提交状态      |

##### 返回示例

```json
{
    "code": 0,
    "data": {
        "total": 3,
        "list": [
            {
                "Shiptask_id": 7810,
                "Shiptask_name": "S96202303086605",
                "order_id": "23365",
                "Purchaseorder_id": null,
                "shipping_way": null,
                "Sales_User_ID": 99,
                "Country_id": 150,
                "State": 1,
                "create_user": 99,
                "Shiptask_delivery_Singlenumber": "222222222222",
                "Shitask_turn_delivery_Singlenumber": "33333333333",
                "state_txt": "待发货",
                "orders": [
                    {
                        "order_id": 23365,
                        "PI_name": "AL2205197698",
                        "Sales_User_ID": 99,
                        "items": [
                            {
                                "order_info_id": 60106,
                                "quantity": 4,
                                "product_name_pi": "6ES7214-1AG40-0XB0",
                                "pods": [
                                    {
                                        "Purchaseorder_detailed_id": 24168,
                                        "Purchaseorder_id": 15522,
                                        "products_Name": "6ES7214-1AG40-0XB0",
                                        "Qtynumber": 4,
                                        "Purchaser_id": 51,
                                        "create_user": 51,
                                        "poInfo": {
                                            "purchaseorder_id": 15522,
                                            "create_user": 51,
                                            "Purchaseordername": "TY2205204632"
                                        },
                                        "simple_submit": [
                                            {
                                                "submit_status_txt": "预交",
                                                "submit_status": 3,
                                                "quantity": 1
                                            }
                                        ],
                                        "user": {
                                            "id": 51,
                                            "name": "liu"
                                        }
                                    }
                                ]
                            },
                            {
                                "order_info_id": 60107,
                                "quantity": 20,
                                "product_name_pi": "CJ1W-CT021",
                                "pods": [
                                    {
                                        "Purchaseorder_detailed_id": 24173,
                                        "Purchaseorder_id": 15526,
                                        "products_Name": "CJ1W-CT021",
                                        "Qtynumber": 20,
                                        "Purchaser_id": 2000,
                                        "create_user": 2000,
                                        "poInfo": {
                                            "purchaseorder_id": 15526,
                                            "create_user": 2000,
                                            "Purchaseordername": "XY2205204573"
                                        },
                                        "simple_submit": [],
                                        "user": {
                                            "id": 2000,
                                            "name": "Wen"
                                        }
                                    }
                                ]
                            }
                        ]
                    }
                ],
                "items": [
                    {
                        "ShioTask_item_id": 18776,
                        "Shiptask_id": 7810,
                        "products_id": 56552,
                        "Purchaseorder_detailed_id": null,
                        "Purchaseordertesk_detailed_id": null,
                        "products_Name": "6ES7214-1AG40-0XB0",
                        "Leading_name": "In Stock",
                        "Model": "6ES7214-1AG40-0XB0",
                        "Qtynumber": 2,
                        "Brand": 1,
                        "Brand_name": "SIEMENS",
                        "Weight": 2,
                        "Purchaser_id": 51,
                        "Picture_url": null,
                        "Sort": 0,
                        "State": 1,
                        "Comments": null,
                        "create_time": "2023-03-08 15:54:46",
                        "create_user": 99,
                        "type": 0,
                        "order_info_id": 60106,
                        "weight_unit": null
                    },
                    {
                        "ShioTask_item_id": 18777,
                        "Shiptask_id": 7810,
                        "products_id": 24406,
                        "Purchaseorder_detailed_id": null,
                        "Purchaseordertesk_detailed_id": null,
                        "products_Name": "CJ1W-CT021",
                        "Leading_name": "In Stock",
                        "Model": "CJ1W-CT021",
                        "Qtynumber": 20,
                        "Brand": 3,
                        "Brand_name": "OMRON",
                        "Weight": 2,
                        "Purchaser_id": 2000,
                        "Picture_url": null,
                        "Sort": 0,
                        "State": 1,
                        "Comments": null,
                        "create_time": "2023-03-08 15:54:46",
                        "create_user": 99,
                        "type": 0,
                        "order_info_id": 60107,
                        "weight_unit": null
                    }
                ],
                "packs": [],
                "user": {
                    "id": 99,
                    "name": "Cathy2022"
                }
            }
        ]
    },
    "time": 0.646
}
```

##### 返回参数说明

| 参数名          | 类型  | 说明                                                                                                                |
|:-------------|:----|-------------------------------------------------------------------------------------------------------------------|
| list[].State | int | 发货状态 1 待发货  2 已发货  3 到货中 4 到货完毕  5 暂无记录 6 在途中 7 派送中 8 已签收 (完结状态) 9 用户拒签 10 疑难件 11 无效单 (完结状态) 12 超时单 13 签收失败 14 退回 |

##### 备注

- 更多返回错误代码请看首页的错误代码描述




