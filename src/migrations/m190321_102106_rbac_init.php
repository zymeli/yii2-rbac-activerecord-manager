<?php
/**
 * Database schema required by \zymeli\yii2RbacActiveRecordManager\ActiveRecordManager.
 * USE: yii migrate --migrationPath=@zymeli/yii2RbacActiveRecordManager/migrations
 *
 * @author zymeli <710055@qq.com>
 */

use yii\base\InvalidConfigException;
use zymeli\yii2RbacActiveRecordManager\ActiveRecordManager;

/**
 * Initializes RBAC tables.
 */
class m190321_102106_rbac_init extends \yii\db\Migration
{
    /**
     * @throws yii\base\InvalidConfigException
     * @return ActiveRecordManager
     */
    protected function getAuthManager()
    {
        $authManager = Yii::$app->getAuthManager();
        if (!$authManager instanceof ActiveRecordManager) {
            throw new InvalidConfigException('You should configure "authManager" component to use database before executing this migration.');
        }

        return $authManager;
    }

    /**
     * @throws yii\base\InvalidConfigException
     */
    protected function ensureMysql()
    {
        if ($this->db->driverName != 'mysql') {
            throw new InvalidConfigException('You should configure "db.mysql" driver to use database before executing this migration.');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function up()
    {
        $authManager = $this->getAuthManager();
        $this->ensureMysql();

        $this->execute("
            SET FOREIGN_KEY_CHECKS=0;
        ");

        $this->execute("
            CREATE TABLE " . $this->db->quoteTableName('{{%auth_rule}}') . " (
              `name` varchar(60) NOT NULL,
              `data` blob,
              `created_at` int(11) DEFAULT NULL,
              `updated_at` int(11) DEFAULT NULL,
              PRIMARY KEY (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=" . ($this->db->charset ?: 'utf8') . " COMMENT='rbac.auth_rule';
        ");

        $this->execute("
            CREATE TABLE " . $this->db->quoteTableName('{{%auth_item}}') . " (
              `item_id` int(9) unsigned NOT NULL AUTO_INCREMENT,
              `is_scope` tinyint(1) unsigned NOT NULL,
              `name` varchar(60) NOT NULL,
              `type` smallint(6) NOT NULL,
              `description` text,
              `rule_name` varchar(60) DEFAULT NULL,
              `data` text,
              `created_at` int(11) DEFAULT NULL,
              `updated_at` int(11) DEFAULT NULL,
              PRIMARY KEY (`item_id`),
              KEY `name` (`name`),
              KEY `rule_name` (`rule_name`),
              KEY `idx-auth_item-type` (`type`),
              CONSTRAINT `auth_item_ibfk_1` FOREIGN KEY (`rule_name`) REFERENCES `auth_rule` (`name`) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=" . ($this->db->charset ?: 'utf8') . " COMMENT='rbac.auth_item';
        ");

        $this->execute("
            CREATE TABLE " . $this->db->quoteTableName('{{%auth_assignment}}') . " (
              `item_id` int(9) unsigned NOT NULL,
              `user_id` varchar(60) NOT NULL,
              `created_at` int(11) DEFAULT NULL,
              PRIMARY KEY (`item_id`,`user_id`),
              KEY `idx-auth_assignment-user_id` (`user_id`),
              CONSTRAINT `auth_assignment_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `auth_item` (`item_id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=" . ($this->db->charset ?: 'utf8') . " COMMENT='rbac.auth_assignment';
        ");

        $this->execute("
            CREATE TABLE " . $this->db->quoteTableName('{{%auth_item_child}}') . " (
              `parent_item_id` int(9) unsigned NOT NULL,
              `child_item_id` int(9) unsigned NOT NULL,
              PRIMARY KEY (`parent_item_id`,`child_item_id`),
              KEY `child_item_id` (`child_item_id`),
              CONSTRAINT `auth_item_child_ibfk_1` FOREIGN KEY (`parent_item_id`) REFERENCES `auth_item` (`item_id`) ON DELETE CASCADE,
              CONSTRAINT `auth_item_child_ibfk_2` FOREIGN KEY (`child_item_id`) REFERENCES `auth_item` (`item_id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=" . ($this->db->charset ?: 'utf8') . " COMMENT='rbac.auth_item_child';
        ");

        $this->execute("
            CREATE TABLE " . $this->db->quoteTableName('{{%auth_scope}}') . " (
              `item_id` int(9) unsigned NOT NULL,
              `scope_id` varchar(60) NOT NULL,
              `created_at` int(11) DEFAULT NULL,
              PRIMARY KEY (`item_id`,`scope_id`),
              KEY `idx-auth_scope_id` (`scope_id`),
              CONSTRAINT `auth_scope_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `auth_item` (`item_id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=" . ($this->db->charset ?: 'utf8') . " COMMENT='rbac.auth_scope';
        ");
    }

    /**
     * {@inheritdoc}
     */
    public function down()
    {
        $this->dropTable('{{%auth_scope}}');
        $this->dropTable('{{%auth_item_child}}');
        $this->dropTable('{{%auth_assignment}}');
        $this->dropTable('{{%auth_item}}');
        $this->dropTable('{{%auth_rule}}');
    }
}
