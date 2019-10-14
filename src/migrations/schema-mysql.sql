/**
 * Database schema required by \zymeli\yii2RbacActiveRecordManager\ActiveRecordManager.
 *
 * @author zymeli <710055@qq.com>
 */

drop table if exists `auth_assignment`;
drop table if exists `auth_item_child`;
drop table if exists `auth_scope`;
drop table if exists `auth_item`;
drop table if exists `auth_rule`;

create table `auth_rule`
(
   `name`                 varchar(64) not null,
   `data`                 blob,
   `created_at`           integer,
   `updated_at`           integer,
    primary key (`name`)
) engine InnoDB;

create table `auth_item`
(
   `item_id`              integer not null AUTO_INCREMENT,
   `is_scope`             tinyint not null,
   `name`                 varchar(64) not null,
   `type`                 smallint not null,
   `description`          text,
   `rule_name`            varchar(64),
   `data`                 blob,
   `created_at`           integer,
   `updated_at`           integer,
   primary key (`item_id`),
   key `name` (`name`),
   foreign key (`rule_name`) references `auth_rule` (`name`) on delete set null on update cascade,
   key `type` (`type`)
) engine InnoDB;

create table `auth_assignment`
(
   `item_id`              integer not null,
   `user_id`              varchar(64) not null,
   `created_at`           integer,
   primary key (`item_id`, `user_id`),
   foreign key (`item_id`) references `auth_item` (`item_id`) on delete cascade on update cascade,
   key `auth_assignment_user_id_idx` (`user_id`)
) engine InnoDB;

create table `auth_item_child`
(
   `parent_item_id`       integer not null,
   `child_item_id`        integer not null,
   primary key (`parent_item_id`,`child_item_id`),
   foreign key (`parent_item_id`) references `auth_item` (`item_id`) on delete cascade on update cascade,
   foreign key (`child_item_id`) references `auth_item` (`item_id`) on delete cascade on update cascade
) engine InnoDB;

create table `auth_scope`
(
   `item_id`              integer not null,
   `scope_id`             varchar(64) not null,
   `created_at`           integer,
   primary key (`item_id`,`scope_id`),
   foreign key (`item_id`) references `auth_item` (`item_id`) on delete cascade on update cascade,
   key `scope_id` (`scope_id`)
) engine InnoDB;
