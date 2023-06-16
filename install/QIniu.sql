CREATE TABLE `cms_qiniu_upload_file`
(
    `id`              int(11) unsigned NOT NULL AUTO_INCREMENT,
    `uuid`            varchar(128) NOT NULL COMMENT '文件UUID',
    `uid`             varchar(128) NOT NULL COMMENT '上传用户UID',
    `bucket`          varchar(255) NOT NULL COMMENT '所属的bucket',
    `key`             varchar(255) NOT NULL COMMENT '资源key',
    `file_name`       varchar(255) NOT NULL COMMENT '文件名',
    `file_type`       varchar(255) NOT NULL COMMENT '文件mime类型',
    `file_ext`        varchar(16)  NOT NULL COMMENT '文件后缀',
    `file_size`       int(11) NOT NULL COMMENT '文件大小，单位byte',
    `file_url`        varchar(255) NOT NULL COMMENT '文件URL',
    `create_time`     int(11) NOT NULL COMMENT '创建时间',
    `update_time`     int(11) DEFAULT NULL COMMENT '更新时间',
    `download_amount` int(11) NOT NULL COMMENT '下载次数',
    `view_amount`     int(11) NOT NULL COMMENT '浏览次数',
    `file_status`     tinyint(1) NOT NULL COMMENT '文件状态 0正常，启用 2禁用',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uuid` (`uuid`),
    KEY               `bucket` (`bucket`(191),`key`(191)),
    KEY               `create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;