5,99
[TOC]

##### 简要描述

- 搜索型号有关的PI信息

##### 请求URL

- ` 192.168.1.15:7880/api/tracking_order/search_pi_orders `

##### 请求方式

- GET

##### 参数

| 参数名        | 必选 | 类型  | 说明        |
|:-----------|:---|:----|-----------|
| page       | 是  | int | 页码，默认：1   |
| size       | 是  | int | 页大小，默认：20 |
| product_id | 是  | int | 快递产品ID    |

##### 返回示例

```json
{
    "code": 0,
    "data": {
        "size": "2",
        "product_id": "116",
        "page": 1,
        "list": [
            {
                "order_id": 23365,
                "PI_name": "AL2205197698",
                "Sales_User_ID": 99,
                "Purchase_User_ID": 0,
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
                                "user": {
                                    "id": 51,
                                    "name": "liu"
                                },
                                "matched": 1,
                                "poInfo": {
                                    "purchaseorder_id": 15522,
                                    "create_user": 51,
                                    "Purchaseordername": "TY2205204632"
                                },
                                "submits": [
                                    {
                                        "id": 21,
                                        "user_id": 1,
                                        "product_id": 116,
                                        "quantity": 1,
                                        "pod_id": 24168,
                                        "submit_status": 3,
                                        "status": 1,
                                        "po_id": 15522,
                                        "order_item_id": 60106,
                                        "express_id": 40,
                                        "created_at": "2023-08-25 18:12:50",
                                        "updated_at": "2023-08-25 18:12:51",
                                        "product": {
                                            "id": 116,
                                            "user_id": 1,
                                            "express_id": 40,
                                            "model": "6ES7214-1AG40-0XB0",
                                            "note": ".",
                                            "quantity": 2,
                                            "submitted_quantity": 2,
                                            "status": 1,
                                            "created_at": "2023-08-25 18:11:36",
                                            "updated_at": "2023-08-28 17:40:03",
                                            "express": {
                                                "id": 40,
                                                "user_id": 44,
                                                "channel_id": 1,
                                                "tracking_number": "SF11111111116",
                                                "sign_status": 1,
                                                "purchaser_id": 51,
                                                "status": 1,
                                                "check_status": 0,
                                                "pre_order_items_ids": null,
                                                "created_at": "2023-06-25 09:33:44",
                                                "updated_at": "2023-08-25 18:12:51",
                                                "channel": {
                                                    "id": 1,
                                                    "name": "顺丰"
                                                },
                                                "purchaser": {
                                                    "id": 51,
                                                    "name": "liu"
                                                }
                                            }
                                        },
                                        "poInfo": {
                                            "purchaseorder_id": 15522,
                                            "create_user": 51,
                                            "Purchaseordername": "TY2205204632"
                                        }
                                    },
                                    {
                                        "id": 22,
                                        "user_id": 1,
                                        "product_id": 116,
                                        "quantity": 1,
                                        "pod_id": 24168,
                                        "submit_status": 3,
                                        "status": 1,
                                        "po_id": 15522,
                                        "order_item_id": 60106,
                                        "express_id": 40,
                                        "created_at": "2023-08-28 17:40:03",
                                        "updated_at": "2023-08-28 17:40:04",
                                        "product": {
                                            "id": 116,
                                            "user_id": 1,
                                            "express_id": 40,
                                            "model": "6ES7214-1AG40-0XB0",
                                            "note": ".",
                                            "quantity": 2,
                                            "submitted_quantity": 2,
                                            "status": 1,
                                            "created_at": "2023-08-25 18:11:36",
                                            "updated_at": "2023-08-28 17:40:03",
                                            "express": {
                                                "id": 40,
                                                "user_id": 44,
                                                "channel_id": 1,
                                                "tracking_number": "SF11111111116",
                                                "sign_status": 1,
                                                "purchaser_id": 51,
                                                "status": 1,
                                                "check_status": 0,
                                                "pre_order_items_ids": null,
                                                "created_at": "2023-06-25 09:33:44",
                                                "updated_at": "2023-08-25 18:12:51",
                                                "channel": {
                                                    "id": 1,
                                                    "name": "顺丰"
                                                },
                                                "purchaser": {
                                                    "id": 51,
                                                    "name": "liu"
                                                }
                                            }
                                        },
                                        "poInfo": {
                                            "purchaseorder_id": 15522,
                                            "create_user": 51,
                                            "Purchaseordername": "TY2205204632"
                                        }
                                    }
                                ],
                                "submitted_quantity": 2,
                                "current_product": {
                                    "submits": [
                                        {
                                            "id": 21,
                                            "user_id": 1,
                                            "product_id": 116,
                                            "quantity": 1,
                                            "pod_id": 24168,
                                            "submit_status": 3,
                                            "status": 1,
                                            "po_id": 15522,
                                            "order_item_id": 60106,
                                            "express_id": 40,
                                            "created_at": "2023-08-25 18:12:50",
                                            "updated_at": "2023-08-25 18:12:51",
                                            "product": {
                                                "id": 116,
                                                "user_id": 1,
                                                "express_id": 40,
                                                "model": "6ES7214-1AG40-0XB0",
                                                "note": ".",
                                                "quantity": 2,
                                                "submitted_quantity": 2,
                                                "status": 1,
                                                "created_at": "2023-08-25 18:11:36",
                                                "updated_at": "2023-08-28 17:40:03",
                                                "express": {
                                                    "id": 40,
                                                    "user_id": 44,
                                                    "channel_id": 1,
                                                    "tracking_number": "SF11111111116",
                                                    "sign_status": 1,
                                                    "purchaser_id": 51,
                                                    "status": 1,
                                                    "check_status": 0,
                                                    "pre_order_items_ids": null,
                                                    "created_at": "2023-06-25 09:33:44",
                                                    "updated_at": "2023-08-25 18:12:51",
                                                    "channel": {
                                                        "id": 1,
                                                        "name": "顺丰"
                                                    },
                                                    "purchaser": {
                                                        "id": 51,
                                                        "name": "liu"
                                                    }
                                                }
                                            },
                                            "poInfo": {
                                                "purchaseorder_id": 15522,
                                                "create_user": 51,
                                                "Purchaseordername": "TY2205204632"
                                            }
                                        },
                                        {
                                            "id": 22,
                                            "user_id": 1,
                                            "product_id": 116,
                                            "quantity": 1,
                                            "pod_id": 24168,
                                            "submit_status": 3,
                                            "status": 1,
                                            "po_id": 15522,
                                            "order_item_id": 60106,
                                            "express_id": 40,
                                            "created_at": "2023-08-28 17:40:03",
                                            "updated_at": "2023-08-28 17:40:04",
                                            "product": {
                                                "id": 116,
                                                "user_id": 1,
                                                "express_id": 40,
                                                "model": "6ES7214-1AG40-0XB0",
                                                "note": ".",
                                                "quantity": 2,
                                                "submitted_quantity": 2,
                                                "status": 1,
                                                "created_at": "2023-08-25 18:11:36",
                                                "updated_at": "2023-08-28 17:40:03",
                                                "express": {
                                                    "id": 40,
                                                    "user_id": 44,
                                                    "channel_id": 1,
                                                    "tracking_number": "SF11111111116",
                                                    "sign_status": 1,
                                                    "purchaser_id": 51,
                                                    "status": 1,
                                                    "check_status": 0,
                                                    "pre_order_items_ids": null,
                                                    "created_at": "2023-06-25 09:33:44",
                                                    "updated_at": "2023-08-25 18:12:51",
                                                    "channel": {
                                                        "id": 1,
                                                        "name": "顺丰"
                                                    },
                                                    "purchaser": {
                                                        "id": 51,
                                                        "name": "liu"
                                                    }
                                                }
                                            },
                                            "poInfo": {
                                                "purchaseorder_id": 15522,
                                                "create_user": 51,
                                                "Purchaseordername": "TY2205204632"
                                            }
                                        }
                                    ],
                                    "submitted_quantity": 2
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
                                "user": {
                                    "id": 2000,
                                    "name": "Wen"
                                },
                                "matched": 0,
                                "poInfo": {
                                    "purchaseorder_id": 15526,
                                    "create_user": 2000,
                                    "Purchaseordername": "XY2205204573"
                                },
                                "submits": [],
                                "submitted_quantity": 0,
                                "current_product": {
                                    "submits": [],
                                    "submitted_quantity": 0
                                }
                            }
                        ]
                    }
                ],
                "sales": {
                    "id": 99,
                    "name": "Cathy2022"
                }
            },
            {
                "order_id": 23360,
                "PI_name": "BE2205197152",
                "Sales_User_ID": 7,
                "Purchase_User_ID": 0,
                "items": [
                    {
                        "order_info_id": 60096,
                        "quantity": 20,
                        "product_name_pi": "6ES7214-1AG40-0XB0",
                        "pods": [
                            {
                                "Purchaseorder_detailed_id": 24169,
                                "Purchaseorder_id": 15522,
                                "products_Name": "6ES7214-1AG40-0XB0",
                                "Qtynumber": 20,
                                "Purchaser_id": 51,
                                "create_user": 51,
                                "user": {
                                    "id": 51,
                                    "name": "liu"
                                },
                                "matched": 1,
                                "poInfo": {
                                    "purchaseorder_id": 15522,
                                    "create_user": 51,
                                    "Purchaseordername": "TY2205204632"
                                },
                                "submits": [],
                                "submitted_quantity": 0,
                                "current_product": {
                                    "submits": [],
                                    "submitted_quantity": 0
                                }
                            }
                        ]
                    },
                    {
                        "order_info_id": 60097,
                        "quantity": 30,
                        "product_name_pi": "6ES7 212-1AE40-0XB0 ",
                        "pods": [
                            {
                                "Purchaseorder_detailed_id": 24170,
                                "Purchaseorder_id": 15523,
                                "products_Name": "6ES7 212-1AE40-0XB0 ",
                                "Qtynumber": 30,
                                "Purchaser_id": 51,
                                "create_user": 51,
                                "user": {
                                    "id": 51,
                                    "name": "liu"
                                },
                                "matched": 0,
                                "poInfo": {
                                    "purchaseorder_id": 15523,
                                    "create_user": 51,
                                    "Purchaseordername": "TY2205207324"
                                },
                                "submits": [],
                                "submitted_quantity": 0,
                                "current_product": {
                                    "submits": [],
                                    "submitted_quantity": 0
                                }
                            }
                        ]
                    }
                ],
                "sales": {
                    "id": 7,
                    "name": "Olivia"
                }
            }
        ]
    },
    "time": 1.735
}
```

```json
{
    "code": 0,
    "data": {
        "list": [
            {
                "order_id": 24694,
                "inquiry_id": 0,
                "PI_name": "E6202303131562",
                "PO_name": "‘’",
                "Version": 0,
                "CreateTime": 1678671655,
                "Sales_User_ID": 99,
                "Customer_Seller_info_id": 5096,
                "Shipping_way": "DHL",
                "Shipping_cost": 0,
                "Shipping_cost_really": 0,
                "Sub_total": 0,
                "Total": 0,
                "Current": "GBP",
                "Current_rate": 6.871,
                "RMB_total": 0,
                "Payment_Way": "T/T",
                "Payment_Receive_Money": 0,
                "Payment_Receive_Date": 0,
                "Type": "Sell",
                "Purchase_User_ID": 0,
                "Belong_To_Orders_ID": 0,
                "Tracking_Number": "‘’",
                "Tracking_Number2": "‘’",
                "State": "Inquiry",
                "Received_Date": 0,
                "address_customer_info_id": 5096,
                "Payment_charge": 0,
                "Invoice_shipping_cost": 0,
                "comments": "‘’",
                "Payment_info_TT": 14,
                "Payment_info_WU": 4,
                "Payment_info_PP": 2,
                "compatible_purchase_value": 0,
                "discount_value": 0,
                "discount_type": "Amount",
                "enable": 1,
                "TT_info_enable": 1,
                "WU_info_enable": 0,
                "PP_info_enable": 0,
                "Commissioned": 0,
                "Commissioned_date": 0,
                "Sales_User_ID_2": 0,
                "fromInquiry": null,
                "leadingtime": 0,
                "Unlock": 0,
                "OrderType": 1,
                "IsSeparate": 0,
                "help_sale_id": null,
                "order_Settle_id": 0,
                "orders_date_id": 0,
                "order_remark": null,
                "amountRmb": "0",
                "company_id": null,
                "status": 1,
                "company_address_id": 0,
                "database_order_id": 0,
                "items": [
                    {
                        "order_info_id": 61377,
                        "order_id": 24694,
                        "product_id": 50,
                        "list_price": 0,
                        "discount": 0,
                        "price": "486.00",
                        "quantity": 89,
                        "price_declare_invoice": 486,
                        "product_name_pi": "USB/MPI+ V4",
                        "product_name_invoice": "USB/MPI+ V4",
                        "product_description_pi": "USB/MPI+ V4",
                        "product_description_invoice": "USB/MPI+ V4",
                        "user_id": 99,
                        "sort": 0,
                        "enable": 1,
                        "update_time": 0,
                        "customer_info_id": 0,
                        "weight": 123,
                        "purchase_for_order_id": 0,
                        "purchase_for_order_item_id": 0,
                        "leadingtime": "1-2 weeks",
                        "product_condition": "New Sealed Under Guarantee",
                        "purchase_price": 0,
                        "inquiry_item_purchase_price_id": null,
                        "QTN_ID": 0,
                        "RFQ_ID": 0,
                        "Purchaser_id": 1021,
                        "Brand_id": 1,
                        "Brand_name": "SIEMENS",
                        "State": 1,
                        "OrdertaskQty": 17,
                        "ShipQty": 23,
                        "SendQty": 0,
                        "TrackQty": 0,
                        "SignQty": 0,
                        "weight_unit": "kg",
                        "database_order_info_id": 0,
                        "database_order_id": 0,
                        "pods": [
                            {
                                "Purchaseorder_detailed_id": 24938,
                                "Purchaseorder_id": 15888,
                                "products_id": 50,
                                "products_Name": "USB/MPI+ V4",
                                "Model": "USB/MPI+ V4",
                                "Qtynumber": 20,
                                "Brand": 1,
                                "Brand_name": "SIEMENS",
                                "Price": "486.00",
                                "Purchase_price": "10.00",
                                "Profit": 4760,
                                "Total": 200,
                                "Weight": 0,
                                "Purchaser_id": 1020,
                                "Picture_url": null,
                                "Sort": 15583,
                                "OccQuantity": 0,
                                "Comments": "",
                                "create_user": 1,
                                "create_time": "2023-03-14 17:44:52",
                                "wareArrival_time": 0,
                                "State": 1,
                                "Leading_name": "1-2 weeks",
                                "order_id": 24694,
                                "Purchaseordertesk_detailed_id": 24549,
                                "ShipQty": null,
                                "complete": 0,
                                "Imprest": 0,
                                "ExpressNumber": null,
                                "overdueProfit": 0,
                                "product_condition": null,
                                "inventory_id": null,
                                "Imprest_number": null,
                                "user": {
                                    "id": 1,
                                    "name": "Tiny"
                                },
                                "matched": 0,
                                "poInfo": {
                                    "purchaseorder_id": 15888,
                                    "order_id": "24694,24694",
                                    "total": 400,
                                    "leading_name": null,
                                    "leading_id": null,
                                    "supplier_id": 10,
                                    "country": 0,
                                    "ip_address": "295879799a0076c6eade99e027a6d88b",
                                    "Comments": null,
                                    "State": 1,
                                    "Sort": 22750,
                                    "Enable": 0,
                                    "Update_tiem": "2023-03-14 17:44:52",
                                    "create_user": 1,
                                    "create_time": "2023-03-14 17:44:52",
                                    "Purchaseordername": "AF202303143218",
                                    "Actualpayment": "0.00",
                                    "Expressdelivery_id": null,
                                    "Exptotal": null,
                                    "Expressdelivery_name": null,
                                    "current": null,
                                    "rate": "0.000",
                                    "sale_id": null,
                                    "is_paied": 0
                                },
                                "submits": [],
                                "submitted_quantity": 0
                            },
                            {
                                "Purchaseorder_detailed_id": 24939,
                                "Purchaseorder_id": 15888,
                                "products_id": 50,
                                "products_Name": "USB/MPI+ V4",
                                "Model": "USB/MPI+ V4",
                                "Qtynumber": 20,
                                "Brand": 1,
                                "Brand_name": "SIEMENS",
                                "Price": "486.00",
                                "Purchase_price": "10.00",
                                "Profit": 4760,
                                "Total": 200,
                                "Weight": 0,
                                "Purchaser_id": 1020,
                                "Picture_url": null,
                                "Sort": 15583,
                                "OccQuantity": 0,
                                "Comments": "",
                                "create_user": 1,
                                "create_time": "2023-03-14 17:44:52",
                                "wareArrival_time": 0,
                                "State": 1,
                                "Leading_name": "1-2 weeks",
                                "order_id": 24694,
                                "Purchaseordertesk_detailed_id": 24549,
                                "ShipQty": null,
                                "complete": 0,
                                "Imprest": 0,
                                "ExpressNumber": null,
                                "overdueProfit": 0,
                                "product_condition": null,
                                "inventory_id": null,
                                "Imprest_number": null,
                                "user": {
                                    "id": 1,
                                    "name": "Tiny"
                                },
                                "matched": 0,
                                "poInfo": {
                                    "purchaseorder_id": 15888,
                                    "order_id": "24694,24694",
                                    "total": 400,
                                    "leading_name": null,
                                    "leading_id": null,
                                    "supplier_id": 10,
                                    "country": 0,
                                    "ip_address": "295879799a0076c6eade99e027a6d88b",
                                    "Comments": null,
                                    "State": 1,
                                    "Sort": 22750,
                                    "Enable": 0,
                                    "Update_tiem": "2023-03-14 17:44:52",
                                    "create_user": 1,
                                    "create_time": "2023-03-14 17:44:52",
                                    "Purchaseordername": "AF202303143218",
                                    "Actualpayment": "0.00",
                                    "Expressdelivery_id": null,
                                    "Exptotal": null,
                                    "Expressdelivery_name": null,
                                    "current": null,
                                    "rate": "0.000",
                                    "sale_id": null,
                                    "is_paied": 0
                                },
                                "submits": [],
                                "submitted_quantity": 0
                            },
                            {
                                "Purchaseorder_detailed_id": 24934,
                                "Purchaseorder_id": 15886,
                                "products_id": 50,
                                "products_Name": "USB/MPI+ V4",
                                "Model": "USB/MPI+ V4",
                                "Qtynumber": 10,
                                "Brand": 1,
                                "Brand_name": "SIEMENS",
                                "Price": "486.00",
                                "Purchase_price": "100.00",
                                "Profit": 386,
                                "Total": 1000,
                                "Weight": 0,
                                "Purchaser_id": 1021,
                                "Picture_url": null,
                                "Sort": 15583,
                                "OccQuantity": 0,
                                "Comments": "有库存",
                                "create_user": 1,
                                "create_time": "2023-03-14 16:11:30",
                                "wareArrival_time": 0,
                                "State": 2,
                                "Leading_name": "1-2 weeks",
                                "order_id": 24694,
                                "Purchaseordertesk_detailed_id": 24550,
                                "ShipQty": null,
                                "complete": 0,
                                "Imprest": 0,
                                "ExpressNumber": null,
                                "overdueProfit": 0,
                                "product_condition": null,
                                "inventory_id": null,
                                "Imprest_number": null,
                                "user": {
                                    "id": 1,
                                    "name": "Tiny"
                                },
                                "matched": 1,
                                "poInfo": {
                                    "purchaseorder_id": 15886,
                                    "order_id": "24694,24694",
                                    "total": 2312,
                                    "leading_name": null,
                                    "leading_id": null,
                                    "supplier_id": 0,
                                    "country": 0,
                                    "ip_address": "本地仓库",
                                    "Comments": null,
                                    "State": 1,
                                    "Sort": 22750,
                                    "Enable": 0,
                                    "Update_tiem": "2023-03-14 16:11:30",
                                    "create_user": 1,
                                    "create_time": "2023-03-14 16:11:30",
                                    "Purchaseordername": "BD202303149085",
                                    "Actualpayment": "0.00",
                                    "Expressdelivery_id": null,
                                    "Exptotal": null,
                                    "Expressdelivery_name": null,
                                    "current": null,
                                    "rate": "0.000",
                                    "sale_id": null,
                                    "is_paied": 0
                                },
                                "submits": [],
                                "submitted_quantity": 0
                            },
                            {
                                "Purchaseorder_detailed_id": 24935,
                                "Purchaseorder_id": 15886,
                                "products_id": 50,
                                "products_Name": "USB/MPI+ V4",
                                "Model": "USB/MPI+ V4",
                                "Qtynumber": 10,
                                "Brand": 1,
                                "Brand_name": "SIEMENS",
                                "Price": "486.00",
                                "Purchase_price": "100.00",
                                "Profit": 386,
                                "Total": 1000,
                                "Weight": 0,
                                "Purchaser_id": 1021,
                                "Picture_url": null,
                                "Sort": 15583,
                                "OccQuantity": 0,
                                "Comments": "有库存",
                                "create_user": 1,
                                "create_time": "2023-03-14 16:11:30",
                                "wareArrival_time": 0,
                                "State": 2,
                                "Leading_name": "1-2 weeks",
                                "order_id": 24694,
                                "Purchaseordertesk_detailed_id": 24550,
                                "ShipQty": null,
                                "complete": 0,
                                "Imprest": 0,
                                "ExpressNumber": null,
                                "overdueProfit": 0,
                                "product_condition": null,
                                "inventory_id": null,
                                "Imprest_number": null,
                                "user": {
                                    "id": 1,
                                    "name": "Tiny"
                                },
                                "matched": 1,
                                "poInfo": {
                                    "purchaseorder_id": 15886,
                                    "order_id": "24694,24694",
                                    "total": 2312,
                                    "leading_name": null,
                                    "leading_id": null,
                                    "supplier_id": 0,
                                    "country": 0,
                                    "ip_address": "本地仓库",
                                    "Comments": null,
                                    "State": 1,
                                    "Sort": 22750,
                                    "Enable": 0,
                                    "Update_tiem": "2023-03-14 16:11:30",
                                    "create_user": 1,
                                    "create_time": "2023-03-14 16:11:30",
                                    "Purchaseordername": "BD202303149085",
                                    "Actualpayment": "0.00",
                                    "Expressdelivery_id": null,
                                    "Exptotal": null,
                                    "Expressdelivery_name": null,
                                    "current": null,
                                    "rate": "0.000",
                                    "sale_id": null,
                                    "is_paied": 0
                                },
                                "submits": [],
                                "submitted_quantity": 0
                            },
                            {
                                "Purchaseorder_detailed_id": 24936,
                                "Purchaseorder_id": 15887,
                                "products_id": 50,
                                "products_Name": "USB/MPI+ V4",
                                "Model": "USB/MPI+ V4",
                                "Qtynumber": 13,
                                "Brand": 1,
                                "Brand_name": "SIEMENS",
                                "Price": "486.00",
                                "Purchase_price": "12.00",
                                "Profit": 3950,
                                "Total": 156,
                                "Weight": 0,
                                "Purchaser_id": 1021,
                                "Picture_url": null,
                                "Sort": 15583,
                                "OccQuantity": 0,
                                "Comments": "",
                                "create_user": 1,
                                "create_time": "2023-03-14 16:11:30",
                                "wareArrival_time": 0,
                                "State": 1,
                                "Leading_name": "1-2 weeks",
                                "order_id": 24694,
                                "Purchaseordertesk_detailed_id": 24550,
                                "ShipQty": null,
                                "complete": 0,
                                "Imprest": 0,
                                "ExpressNumber": null,
                                "overdueProfit": 0,
                                "product_condition": null,
                                "inventory_id": null,
                                "Imprest_number": null,
                                "user": {
                                    "id": 1,
                                    "name": "Tiny"
                                },
                                "matched": 0,
                                "poInfo": {
                                    "purchaseorder_id": 15887,
                                    "order_id": "24694,24694",
                                    "total": 2312,
                                    "leading_name": null,
                                    "leading_id": null,
                                    "supplier_id": 10,
                                    "country": 0,
                                    "ip_address": "295879799a0076c6eade99e027a6d88b",
                                    "Comments": null,
                                    "State": 1,
                                    "Sort": 22750,
                                    "Enable": 0,
                                    "Update_tiem": "2023-03-14 16:11:30",
                                    "create_user": 1,
                                    "create_time": "2023-03-14 16:11:30",
                                    "Purchaseordername": "AF202303143509",
                                    "Actualpayment": "0.00",
                                    "Expressdelivery_id": null,
                                    "Exptotal": null,
                                    "Expressdelivery_name": null,
                                    "current": null,
                                    "rate": "0.000",
                                    "sale_id": null,
                                    "is_paied": 0
                                },
                                "submits": [],
                                "submitted_quantity": 0
                            },
                            {
                                "Purchaseorder_detailed_id": 24937,
                                "Purchaseorder_id": 15887,
                                "products_id": 50,
                                "products_Name": "USB/MPI+ V4",
                                "Model": "USB/MPI+ V4",
                                "Qtynumber": 13,
                                "Brand": 1,
                                "Brand_name": "SIEMENS",
                                "Price": "486.00",
                                "Purchase_price": "12.00",
                                "Profit": 3950,
                                "Total": 156,
                                "Weight": 0,
                                "Purchaser_id": 1021,
                                "Picture_url": null,
                                "Sort": 15583,
                                "OccQuantity": 0,
                                "Comments": "",
                                "create_user": 1,
                                "create_time": "2023-03-14 16:11:30",
                                "wareArrival_time": 0,
                                "State": 1,
                                "Leading_name": "1-2 weeks",
                                "order_id": 24694,
                                "Purchaseordertesk_detailed_id": 24550,
                                "ShipQty": null,
                                "complete": 0,
                                "Imprest": 0,
                                "ExpressNumber": null,
                                "overdueProfit": 0,
                                "product_condition": null,
                                "inventory_id": null,
                                "Imprest_number": null,
                                "user": {
                                    "id": 1,
                                    "name": "Tiny"
                                },
                                "matched": 0,
                                "poInfo": {
                                    "purchaseorder_id": 15887,
                                    "order_id": "24694,24694",
                                    "total": 2312,
                                    "leading_name": null,
                                    "leading_id": null,
                                    "supplier_id": 10,
                                    "country": 0,
                                    "ip_address": "295879799a0076c6eade99e027a6d88b",
                                    "Comments": null,
                                    "State": 1,
                                    "Sort": 22750,
                                    "Enable": 0,
                                    "Update_tiem": "2023-03-14 16:11:30",
                                    "create_user": 1,
                                    "create_time": "2023-03-14 16:11:30",
                                    "Purchaseordername": "AF202303143509",
                                    "Actualpayment": "0.00",
                                    "Expressdelivery_id": null,
                                    "Exptotal": null,
                                    "Expressdelivery_name": null,
                                    "current": null,
                                    "rate": "0.000",
                                    "sale_id": null,
                                    "is_paied": 0
                                },
                                "submits": [],
                                "submitted_quantity": 0
                            }
                        ]
                    }
                ],
                "sales": {
                    "id": 99,
                    "name": "Cathy2022"
                }
            }
        ]
    },
    "time": 5.023
}
```

##### 返回参数说明

| 参数名     | 类型  | 说明                                                          |
|:--------|:----|-------------------------------------------------------------|
| matched | int | 是否匹配，0 不匹配 1 匹配，匹配的才显示提交按钮就好了，不匹配的意思是pod记录的型号和搜索的型号不一致，需要忽略 |

##### 备注

- 更多返回错误代码请看首页的错误代码描述




