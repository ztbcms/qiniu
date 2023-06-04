CREATE TABLE `cms_qiniu_upload_file`
(
    `id`              int(11) unsigned NOT NULL AUTO_INCREMENT,
    `uuid`            varchar(128) NOT NULL COMMENT '文件UUID',
    `bucket`          varchar(255) NOT NULL COMMENT '所属的bucket',
    `key`             varchar(255) NOT NULL COMMENT '资源key',
    `file_name`       varchar(255) NOT NULL COMMENT '文件名',
    `file_type`       varchar(255) NOT NULL COMMENT '文件mime类型',
    `file_ext`        varchar(16)  NOT NULL COMMENT '文件后缀',
    `file_size`       int(11) NOT NULL COMMENT '文件大小，单位byte',
    `file_url`        varchar(255) NOT NULL COMMENT '文件URL',
    `create_time`     int(11) NOT NULL,
    `update_time`     int(11) DEFAULT NULL,
    `download_amount` int(11) NOT NULL COMMENT '下载次数',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uuid` (`uuid`),
    KEY               `bucket` (`bucket`(191),`key`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;