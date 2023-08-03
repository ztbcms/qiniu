CREATE TABLE `cms_qiniu_upload_file`
(
    `id`              int(11) unsigned NOT NULL AUTO_INCREMENT,
    `uuid`            varchar(128) NOT NULL DEFAULT '' COMMENT '文件UUID',
    `bucket`          varchar(255) NOT NULL DEFAULT '' COMMENT '所属的bucket',
    `key`             varchar(255) NOT NULL DEFAULT '' COMMENT '资源key',
    `file_name`       varchar(255) NOT NULL DEFAULT '' COMMENT '文件名',
    `file_type`       varchar(255) NOT NULL DEFAULT '' COMMENT '文件mime类型',
    `file_ext`        varchar(16)  NOT NULL DEFAULT '' COMMENT '文件后缀',
    `file_size`       int(11) NOT NULL DEFAULT '0' COMMENT '文件大小，单位byte',
    `file_url`        varchar(255) NOT NULL DEFAULT '' COMMENT '文件URL',
    `create_time`     int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
    `update_time`     int(11) NOT NULL DEFAULT '0' COMMENT '更新时间',
    `download_amount` int(11) NOT NULL DEFAULT '0' COMMENT '下载次数',
    `view_amount`     int(11) NOT NULL DEFAULT '0' COMMENT '浏览次数',
    `file_status`     tinyint(1) NOT NULL DEFAULT '0' COMMENT '文件状态 0正常，启用 1禁用',
    `fetch_status`    tinyint(1) NOT NULL DEFAULT '0' COMMENT '拉取状态0拉取中，1拉取完成2拉取失败',
    `fetch_url`       text COMMENT '抓取的url',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uuid` (`uuid`),
    KEY               `bucket` (`bucket`(191),`key`(191)),
    KEY               `create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `cms_qiniu_fetch_file`
(
    `id`              int(11) unsigned NOT NULL AUTO_INCREMENT,
    `uuid`            varchar(128) NOT NULL DEFAULT '' COMMENT '文件UUID',
    `bucket`          varchar(255) NOT NULL DEFAULT '' COMMENT '所属的bucket',
    `key`             varchar(255) NOT NULL DEFAULT '' COMMENT '资源key',
    `file_name`       varchar(255) NOT NULL DEFAULT '' COMMENT '文件名',
    `file_type`       varchar(255) NOT NULL DEFAULT '' COMMENT '文件mime类型',
    `file_ext`        varchar(16)  NOT NULL DEFAULT '' COMMENT '文件后缀',
    `file_size`       int(11) NOT NULL DEFAULT '0' COMMENT '文件大小，单位byte',
    `file_url`        varchar(255) NOT NULL DEFAULT '' COMMENT '文件URL',
    `create_time`     int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
    `update_time`     int(11) NOT NULL DEFAULT '0' COMMENT '更新时间',
    `download_amount` int(11) NOT NULL DEFAULT '0' COMMENT '下载次数',
    `view_amount`     int(11) NOT NULL DEFAULT '0' COMMENT '浏览次数',
    `file_status`     tinyint(1) NOT NULL DEFAULT '0' COMMENT '文件状态 0正常，启用 1禁用',
    `fetch_status`    tinyint(1) NOT NULL DEFAULT '0' COMMENT '拉取状态0拉取中，1拉取完成2拉取失败',
    `fetch_url`       text COMMENT '抓取的url',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uuid` (`uuid`),
    KEY               `bucket` (`bucket`(191),`key`(191)),
    KEY               `create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;