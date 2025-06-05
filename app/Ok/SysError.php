<?php

namespace App\Ok;

class SysError
{
    # [$code, $message] = SysError::SYSTEM_ERROR;

    const SYSTEM_ERROR = [1, '系统错误',];

    const TOKEN_EXPIRED = [2, '登录状态已过期',];

    const PERMISSION_ERROR = [4, '权限错误',];

    const LOGIN_ERROR = [20, '登录失败，用户名或密码错误',];

    const PARAMETER_ERROR = [21, '参数错误',];

    const USERNAME_ERROR = [22, '用户不存在',];

    const PASSWORD_ERROR = [23, '密码错误',];

    const USER_INFO_ERROR = [24, '用户信息获取失败',];

    const EXTENSION_ERROR = [66, '文件类型不允许',];

    const COMPANY_NO_FOUND_ERROR = [67, '快递公司不存在或已删除',];

    const COMPANY_DUPLICATE_ERROR = [68, '公司名已经存在',];

    const EXPRESS_DELIVERY_ALREADY_EXISTS_ERROR = [69, '快递号已存在',];

    const PRODUCT_ID_ERROR = [70, '产品ID错误',];

    const QUANTITY_ERROR = [71, '产品数量不足',];

    const PRODUCT_EXISTS_ERROR = [72, '产品已存在',];

    const POD_ID_ERROR = [73, '采购订单ID或状态错误',];

    const POD_ID_FULL_ERROR = [74, '提交的数量不能超过采购订单需要的数量',];

    const ATTACHMENT_DOES_NOT_EXIST_ERROR = [75, '附件不存在',];

    const ATTACHMENT_EXIST_ERROR = [75, '附件已存在',];

    const QUANTITY_NOT_MATCH_ERROR = [76, '数量不匹配',];

    const SERIAL_NUMBER_ID_ERROR = [77, '产品序列号ID错误',];

    const SO_ID_ERROR = [78, 'SO订单不存在',];

    const SHIPPING_WAY_ERROR = [79, 'Shipping Way 更新错误',];

    const EXP_ID_ERROR = [80, '快递不存在',];

    const ADD_CHANNEL_FAIL = [81, '添加快递渠道失败',];

    const DELIVERY_ALREADY_EXISTS = [82, '快递已存在',];

    const CANCEL_SUBMIT_STATUS_CHECK_ERROR = [83, '预交以上的状态不允许撤销',];

    const OPERATION_NOT_DEFINED = [84, '当前提交记录状态下操作未定义',];

    const EXPRESS_NO_EMPTY = [85, '快递列表不为空，不允许删除',];

    const SUBMITTED_QUANTITY = [86, '已提交数量大于0，不允许删除',];

    const PRODUCT_NO_EXISTS_ERROR = [87, '产品不存在',];

    const PRODUCT_MODEL_EXISTS_ERROR = [88, '快递内已存在相同产品',];

    const SERIAL_NUMBER_ALREADY_EXISTS_ERROR = [89, '已存在相同的序列号',];

    const SERIAL_NUMBER_NO_EXISTS_ERROR = [90, '序列号不存在',];

    const PRODUCT_MODEL_EXISTS_SERIAL_NUMBER_ERROR = [91, '快递产品下存在序列号，不允许删除',];

    const EXPRESS_EXISTS_PRODUCT_MODEL_ERROR = [91, '快递下存在产品，不允许删除',];

    const EXPRESS_STATUS_NO_ROll_BACK_ERROR = [91, '已签收的快递状态不能改为待收',];

    const SERIAL_NUMBER_ERROR = [92, '序列号数量大于缺货数',];

    const EXPRESS_STATUS_NO_SIGN_NOT_SUBMIT_ERROR = [93, '快递状态为待签收不能提交产品',];

    const MULTIPLE_SALES_EXIST = [93, '存在多个销售，不能合并发货',];

    const HTTP_NOT_FOUND = [404, '请求的API不存在',];

    const METHOD_NOT_ALLOWED = [405, '请求方法不允许',];

    const HTTP_TOO_MANY_REQUESTS = [429, '请求频率过高，请稍后重试',];

    const ES_NOT_FOUND = [430, '请求的资源不存在',];

}
