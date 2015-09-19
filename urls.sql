CREATE TABLE `urls` (
    `id` INT (11) NOT NULL AUTO_INCREMENT,
    `sha1` CHAR (40) NOT NULL,
    `url` VARCHAR (255) NOT NULL,
    `create_at` INT (11) NOT NULL,
    `creator` INT (11) NOT NULL DEFAULT '0',
    `count` INT (11) NOT NULL DEFAULT '0',
    PRIMARY KEY (`id`),
    INDEX (`sha1`),
    INDEX (`create_at`),
    INDEX (`creator`),
    INDEX (`count`)
) ENGINE = INNODB CHARACTER
SET utf8 COLLATE utf8_unicode_ci;