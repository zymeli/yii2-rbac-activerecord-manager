<?php

namespace zymeli\yii2RbacActiveRecordManager\models;

use Yii;
use yii\rbac\Assignment;

/**
 * This is the model class for table "{{%auth_assignment}}".
 *
 * @property integer $item_id dbField: int(9) unsigned
 * @property string $user_id dbField: varchar(60)
 * @property integer $created_at dbField: int(11) null
 *
 * @property-read Assignment $assignmentObject get populated this to Assignment
 *
 * @property AuthItem $authItem
 */
class AuthAssignment extends \yii\db\ActiveRecord
{
    /** @var bool the assignment.object is populated */
    private $_is_populated = false;
    private $_assignmentObject;

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%auth_assignment}}';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['item_id', 'user_id'], 'required'],
            [['item_id'], 'integer', 'min' => 1, 'max' => 999999999],
            [['user_id'], 'string', 'max' => 60],
            [['created_at'], 'integer', 'min' => 0],
            [['created_at'], 'default', 'value' => time()],
            [['item_id', 'user_id'], 'unique', 'targetAttribute' => ['item_id', 'user_id']],
            [['item_id'], 'exist', 'skipOnError' => true, 'targetClass' => AuthItem::class, 'targetAttribute' => ['item_id' => 'item_id']],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'item_id' => 'Item ID',
            'user_id' => 'User ID',
            'created_at' => 'Created At',
        ];
    }

    /**
     * populate data and this
     */
    public function populateObject()
    {
        if (!$this->_is_populated) {
            $this->_assignmentObject = new Assignment([
                'userId' => $this->user_id,
                'roleName' => $this->authItem->name,
                'createdAt' => $this->created_at,
            ]);
        }
        $this->_is_populated = true;
    }

    /**
     * @return Assignment
     */
    public function getAssignmentObject()
    {
        $this->_is_populated or $this->populateObject();
        return $this->_assignmentObject;
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getAuthItem()
    {
        return $this->hasOne(AuthItem::class, ['item_id' => 'item_id']);
    }
}
