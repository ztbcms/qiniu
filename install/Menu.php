<?php

return array(
    array(
        //父菜单ID，NULL或者不写系统默认，0为顶级菜单
        "parentid" => 39,
        //地址，[模块/]控制器/方法
        "route" => "qiniu/index/index",
        //是否验证菜单，1：验证，0：不验证
        "type" => 0,
        //状态，1是显示，0不显示（需要参数的，建议不显示，例如编辑,删除等操作）
        "status" => 1,
        //名称
        "name" => "七牛云",
        //备注
        "remark" => "",
        "icon" => "icon-empty",
        //子菜单列表
        "child" => [
            [
                "route" => "qiniu/admin/files",
                "type" => 1,
                "status" => 1,
                "name" => "上传管理",
            ],
        ],
    ),
);
