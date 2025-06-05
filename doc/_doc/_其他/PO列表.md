2,99
[TOC]

##### 简要描述

- PI列表

##### 请求URL

- ` 192.168.1.15:7880/api/tracking_order/po/list `

##### 请求方式

- GET

##### 参数

| 参数名           | 必选 | 类型     | 说明                      |
|:--------------|:---|:-------|-------------------------|
| page          | 是  | int    | 页码，默认：1                 |
| size          | 是  | int    | 页大小，默认：20               |
| keyword       | 否  | string | 搜索词                     |
| purchaser_id  | 否  | int    | 采购ID                    |
| sales_id      | 否  | int    | 销售ID                    |
| submit_status | 否  | int    | 提交状态                    |
| po_id         | 否  | int    | PO ID(purchaseorder_id) |

##### 返回示例

```json
{
    "code": 0,
    "data": {
        "total": 10000,
        "list": [
            {
                "purchaseorder_id": 16099,
                "create_user": 51,
                "Purchaseordername": "F62307204043",
                "purchaser": {
                    "id": 51,
                    "name": "liu"
                },
                "groups": [
                    {
                        "model": "PZ-G101CP",
                        "list": [
                            {
                                "Purchaseorder_detailed_id": 16333,
                                "Purchaseorder_id": 10503,
                                "products_Name": "PZ-G101CP",
                                "Qtynumber": 2,
                                "Purchaser_id": 1020,
                                "order_id": 19880,
                                "order": {
                                    "order_id": 19880,
                                    "PI_name": "AL2201144374",
                                    "Sales_User_ID": 99,
                                    "sales": {
                                        "id": 99,
                                        "name": "Cathy2022"
                                    },
                                    "sois": []
                                },
                                "submits": []
                            }
                        ]
                    }
                ]
            }
        ]
    },
    "time": 0.476
}
```

##### 返回参数说明

| 参数名 | 类型 | 说明 |
|:----|:---|----|
| -   | -  | -  |

##### 备注

- 更多返回错误代码请看首页的错误代码描述




