<?php

namespace zymeli\yii2RbacActiveRecordManager;

use Yii;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\base\InvalidCallException;
use yii\caching\CacheInterface;
use yii\di\Instance;
use yii\helpers\ArrayHelper;
use yii\rbac\BaseManager;
use yii\rbac\Assignment;
use yii\rbac\Item;
use yii\rbac\Permission;
use yii\rbac\Role;
use yii\rbac\Rule;
use zymeli\yii2RbacActiveRecordManager\models\AuthRule;
use zymeli\yii2RbacActiveRecordManager\models\AuthItem;
use zymeli\yii2RbacActiveRecordManager\models\AuthAssignment;
use zymeli\yii2RbacActiveRecordManager\models\AuthItemChild;
use zymeli\yii2RbacActiveRecordManager\models\AuthScope;

class ActiveRecordManager extends BaseManager
{
    /**
     * @var false|string|callable the scope. If false then data will check without scope.
     */
    public $scope = false;

    /**
     * @var CacheInterface|array|string the cache used to improve RBAC performance.
     */
    public $cache;

    /**
     * @var string the key prefix used to store RBAC data in cache
     */
    public $cacheKey = 'rbac';

    /**
     * @var Item[] all auth items (name => Item)
     */
    protected $items;

    /**
     * @var Rule[] all auth rules (name => Rule)
     */
    protected $rules;

    /**
     * @var array auth item parent-child relationships (childName => list of parents)
     */
    protected $parents;

    /**
     * Initializes the application component.
     * This method overrides the parent implementation by establishing the database connection.
     */
    public function init()
    {
        parent::init();
        $this->scope = strval(is_callable($this->scope) ? call_user_func($this->scope) : $this->scope);
        if ($this->scope == '') {
            throw new InvalidConfigException("Scope not found");
        }
        $this->cacheKey = $this->cacheKey . '_' . $this->scope;
        if ($this->cache !== null) {
            $this->cache = Instance::ensure($this->cache, 'yii\caching\CacheInterface');
        }
    }

    private $_checkAccessAssignments = [];

    /**
     * {@inheritdoc}
     */
    public function checkAccess($userId, $permissionName, $params = [])
    {
        if (isset($this->_checkAccessAssignments[(string)$userId])) {
            $assignments = $this->_checkAccessAssignments[(string)$userId];
        } else {
            $assignments = $this->getAssignments($userId);
            $this->_checkAccessAssignments[(string)$userId] = $assignments;
        }

        if ($this->hasNoAssignments($assignments)) {
            return false;
        }

        $this->loadFromCache();
        if ($this->items !== null) {
            return $this->checkAccessFromCache($userId, $permissionName, $params, $assignments);
        }

        return $this->checkAccessRecursive($userId, $permissionName, $params, $assignments);
    }

    /**
     * Performs access check for the specified user based on the data loaded from cache.
     * This method is internally called by [[checkAccess()]] when [[cache]] is enabled.
     * @param string|int $user the user ID. This should can be either an integer or a string representing
     * the unique identifier of a user. See [[\yii\web\User::id]].
     * @param string $itemName the name of the operation that need access check
     * @param array $params name-value pairs that would be passed to rules associated
     * with the tasks and roles assigned to the user. A param with name 'user' is added to this array,
     * which holds the value of `$userId`.
     * @param Assignment[] $assignments the assignments to the specified user
     * @return bool whether the operations can be performed by the user.
     * @throws \Exception
     */
    protected function checkAccessFromCache($user, $itemName, $params, $assignments)
    {
        if (!isset($this->items[$itemName])) {
            return false;
        }

        $item = $this->items[$itemName];

        Yii::debug($item instanceof Role ? "Checking role: $itemName" : "Checking permission: $itemName", __METHOD__);

        if (!$this->executeRule($user, $item, $params)) {
            return false;
        }

        if (isset($assignments[$itemName]) || in_array($itemName, $this->defaultRoles)) {
            return true;
        }

        if (!empty($this->parents[$itemName])) {
            foreach ($this->parents[$itemName] as $parent) {
                if ($this->checkAccessFromCache($user, $parent, $params, $assignments)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Performs access check for the specified user.
     * This method is internally called by [[checkAccess()]].
     * @param string|int $user the user ID. This should can be either an integer or a string representing
     * the unique identifier of a user. See [[\yii\web\User::id]].
     * @param string $itemName the name of the operation that need access check
     * @param array $params name-value pairs that would be passed to rules associated
     * with the tasks and roles assigned to the user. A param with name 'user' is added to this array,
     * which holds the value of `$userId`.
     * @param Assignment[] $assignments the assignments to the specified user
     * @return bool whether the operations can be performed by the user.
     * @throws \Exception
     */
    protected function checkAccessRecursive($user, $itemName, $params, $assignments)
    {
        if (($item = $this->getItem($itemName)) === null) {
            return false;
        }

        Yii::debug($item instanceof Role ? "Checking role: $itemName" : "Checking permission: $itemName", __METHOD__);

        if (!$this->executeRule($user, $item, $params)) {
            return false;
        }

        if (isset($assignments[$itemName]) || in_array($itemName, $this->defaultRoles)) {
            return true;
        }

        $authItem = $this->getAuthItemByName($itemName);
        $parents = AuthItem::find()->joinWith('authParents')->where(['child_item_id' => $authItem->item_id])->select('name')->column();
        foreach ($parents as $parent) {
            if ($this->checkAccessRecursive($user, $parent, $params, $assignments)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param $name
     * @return AuthItem|null
     */
    protected function getAuthItemByName($name)
    {
        /** @var AuthItem $authItem */
        $authItem = AuthItem::find()->where(['name' => $name])->andWhere(['is_scope' => false])->one();
        if ($authItem) return $authItem;
        $authItem = AuthItem::find()->where(['name' => $name])->joinWith('authScopes')->andWhere(['scope_id' => $this->scope])->one();
        return $authItem;
    }

    /**
     * @param $name
     * @return AuthRule|null
     */
    protected function getAuthRuleByName($name)
    {
        /** @var AuthRule $authRule */
        $authRule = AuthRule::find()->where(['name' => $name])->one();
        return $authRule;
    }

    /**
     * {@inheritdoc}
     */
    protected function getItem($name)
    {
        if (empty($name)) {
            return null;
        }

        if (!empty($this->items[$name])) {
            return $this->items[$name];
        }

        $authItem = $this->getAuthItemByName($name);
        return ($authItem ? $authItem->getItemObject() : null);
    }

    /**
     * Returns a value indicating whether the database supports cascading update and delete.
     * The default implementation will return false for SQLite database and true for all other databases.
     * @return bool whether the database supports cascading update and delete.
     */
    protected function supportsCascadeUpdate()
    {
        return strncmp(AuthItem::getDb()->getDriverName(), 'sqlite', 6) !== 0;
    }

    /**
     * {@inheritdoc}
     */
    protected function addItem($item)
    {
        $time = time();
        if ($item->createdAt === null) {
            $item->createdAt = $time;
        }
        if ($item->updatedAt === null) {
            $item->updatedAt = $time;
        }

        $authItem = new AuthItem();
        $authItem->name = $item->name;
        $authItem->type = $item->type;
        $authItem->description = $item->description;
        $authItem->rule_name = $item->ruleName;
        $authItem->data = ($item->data === null ? null : serialize($item->data));
        $authItem->created_at = $item->createdAt;
        $authItem->updated_at = $item->updatedAt;
        $authItem->save();

        if ($authItem->is_scope) {
            $authScope = new AuthScope();
            $authScope->item_id = $authItem->item_id;
            $authScope->scope_id = $this->scope;
            $authScope->created_at = $authItem->created_at;
            $authScope->save();
        }

        $this->invalidateCache();

        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function removeItem($item)
    {
        $authItem = $this->getAuthItemByName($item->name);

        if (!$this->supportsCascadeUpdate()) {
            AuthItemChild::deleteAll(['or', ['parent_item_id' => $authItem->item_id], ['child_item_id' => $authItem->item_id]]);
            AuthAssignment::deleteAll(['item_id' => $authItem->item_id]);
        }

        $authItem->delete();

        $this->invalidateCache();

        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function updateItem($name, $item)
    {
        $item->updatedAt = time();

        $authItem = $this->getAuthItemByName($name);
        if ($authItem) {
            $authItem->name = $item->name;
            $authItem->description = $item->description;
            $authItem->rule_name = $item->ruleName;
            $authItem->data = ($item->data === null ? null : serialize($item->data));
            $authItem->updated_at = $item->updatedAt;
            $authItem->save();
        }

        $this->invalidateCache();

        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function addRule($rule)
    {
        $time = time();
        if ($rule->createdAt === null) {
            $rule->createdAt = $time;
        }
        if ($rule->updatedAt === null) {
            $rule->updatedAt = $time;
        }

        $authRule = new AuthRule();
        $authRule->name = $rule->name;
        $authRule->data = serialize($rule);
        $authRule->created_at = $rule->createdAt;
        $authRule->updated_at = $rule->updatedAt;
        $authRule->save();

        $this->invalidateCache();

        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function updateRule($name, $rule)
    {
        if ($rule->name !== $name && !$this->supportsCascadeUpdate()) {
            AuthItem::updateAll(['rule_name' => $rule->name], ['rule_name' => $name]);
        }

        $rule->updatedAt = time();

        $authRule = $this->getAuthRuleByName($name);
        if ($authRule) {
            $authRule->name = $rule->name;
            $authRule->data = serialize($rule);
            $authRule->updated_at = $rule->updatedAt;
            $authRule->save();
        }

        $this->invalidateCache();

        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function removeRule($rule)
    {
        if (!$this->supportsCascadeUpdate()) {
            AuthItem::updateAll(['rule_name' => null], ['rule_name' => $rule->name]);
        }

        $authRule = $this->getAuthRuleByName($rule->name);
        $authRule->delete();

        $this->invalidateCache();

        return true;
    }

    /**
     * @return AuthRule[]
     */
    protected function getAuthRules()
    {
        /** @var AuthRule[] $authRules */
        $authRules = AuthRule::find()->all();
        return $authRules;
    }

    /**
     * @param null|int $type item.type
     * @return AuthItem[]
     */
    protected function getAuthItems($type = null)
    {
        /** @var AuthItem[] $authItems */
        $where = ($type === null ? [] : ['type' => $type]);
        $authItems = AuthItem::find()->where($where)->andWhere(['is_scope' => false])->all();
        $_more = AuthItem::find()->where($where)->joinWith('authScopes')->andWhere(['scope_id' => $this->scope])->all();
        $authItems = array_merge($authItems, $_more);
        return $authItems;
    }

    /**
     * {@inheritdoc}
     */
    protected function getItems($type = null)
    {
        $authItems = $this->getAuthItems($type);
        $items = [];
        foreach ($authItems as $authItem) {
            $items[$authItem->name] = $authItem->getItemObject();
        }
        return $items;
    }

    /**
     * {@inheritdoc}
     * The roles returned by this method include the roles assigned via [[$defaultRoles]].
     */
    public function getRolesByUser($userId)
    {
        if ($this->isEmptyUserId($userId)) {
            return [];
        }

        $roles = $this->getDefaultRoleInstances();

        $authItems = $this->getAuthItems(Item::TYPE_ROLE);
        $ids = ArrayHelper::getColumn($authItems, 'item_id');
        $authAssignments = AuthAssignment::find()->where(['user_id' => $userId])->andWhere(['item_id' => $ids])->all();
        $ids = ArrayHelper::getColumn($authAssignments, 'item_id');
        foreach ($authItems as $authItem) {
            if (in_array($authItem->item_id, $ids)) {
                $roles[$authItem->name] = $authItem->getItemObject();
            }
        }

        return $roles;
    }

    /**
     * {@inheritdoc}
     */
    public function getChildRoles($roleName)
    {
        $role = $this->getRole($roleName);

        if ($role === null) {
            throw new InvalidArgumentException("Role \"$roleName\" not found.");
        }

        $result = [];
        $this->getChildrenRecursive($roleName, $this->getChildrenList(), $result);

        $roles = [$roleName => $role];

        $roles += array_filter($this->getRoles(), function (Role $roleItem) use ($result) {
            return array_key_exists($roleItem->name, $result);
        });

        return $roles;
    }

    /**
     * {@inheritdoc}
     */
    public function getPermissionsByRole($roleName)
    {
        $result = [];
        $this->getChildrenRecursive($roleName, $this->getChildrenList(), $result);
        if (empty($result)) {
            return [];
        }

        $permissions = [];
        $names = array_keys($result);
        $authItems = $this->getAuthItems(Item::TYPE_PERMISSION);
        foreach ($authItems as $authItem) {
            if (in_array($authItem->name, $names)) {
                $permissions[$authItem->name] = $authItem->getItemObject();
            }
        }

        return $permissions;
    }

    /**
     * {@inheritdoc}
     */
    public function getPermissionsByUser($userId)
    {
        if ($this->isEmptyUserId($userId)) {
            return [];
        }

        $directPermission = $this->getDirectPermissionsByUser($userId);
        $inheritedPermission = $this->getInheritedPermissionsByUser($userId);

        return array_merge($directPermission, $inheritedPermission);
    }

    /**
     * Returns all permissions that are directly assigned to user.
     * @param string|int $userId the user ID (see [[\yii\web\User::id]])
     * @return Permission[] all direct permissions that the user has. The array is indexed by the permission names.
     */
    protected function getDirectPermissionsByUser($userId)
    {
        $authItems = $this->getAuthItems(Item::TYPE_PERMISSION);
        $ids = ArrayHelper::getColumn($authItems, 'item_id');
        $authAssignments = AuthAssignment::find()->where(['user_id' => $userId])->andWhere(['item_id' => $ids])->all();
        $ids = ArrayHelper::getColumn($authAssignments, 'item_id');

        $permissions = [];
        foreach ($authItems as $authItem) {
            if (in_array($authItem->item_id, $ids)) {
                $permissions[$authItem->name] = $authItem->getItemObject();
            }
        }

        return $permissions;
    }

    /**
     * Returns all permissions that the user inherits from the roles assigned to him.
     * @param string|int $userId the user ID (see [[\yii\web\User::id]])
     * @return Permission[] all inherited permissions that the user has. The array is indexed by the permission names.
     */
    protected function getInheritedPermissionsByUser($userId)
    {
        $authItems = $this->getAuthItems();
        $ids = ArrayHelper::getColumn($authItems, 'item_id');
        $authAssignments = AuthAssignment::find()->where(['user_id' => $userId])->andWhere(['item_id' => $ids])->all();
        $ids = ArrayHelper::getColumn($authAssignments, 'item_id');

        $result = [];
        foreach ($authItems as $authItem) {
            if (in_array($authItem->item_id, $ids)) {
                $this->getChildrenRecursive($authItem->name, $this->getChildrenList(), $result);
            }
        }

        if (empty($result)) {
            return [];
        }

        $permissions = [];
        $names = array_keys($result);
        foreach ($authItems as $authItem) {
            if (in_array($authItem->name, $names) and $authItem->isPermission()) {
                $permissions[$authItem->name] = $authItem->getItemObject();
            }
        }

        return $permissions;
    }

    /**
     * Returns the children for every parent.
     * @return array the children list. Each array key is a parent item name,
     * and the corresponding array value is a list of child item names.
     */
    protected function getChildrenList()
    {
        $authItems = $this->getAuthItems();
        $ids = ArrayHelper::getColumn($authItems, 'item_id');
        /** @var AuthItemChild[] $authItemChilds */
        $authItemChilds = AuthItemChild::find()->where(['parent_item_id' => $ids, 'child_item_id' => $ids])->with('parentAuthItem', 'childAuthItem')->all();

        $parents = [];
        foreach ($authItemChilds as $row) {
            $parents[$row->parentAuthItem->name][] = $row->childAuthItem->name;
        }

        return $parents;
    }

    /**
     * Recursively finds all children and grand children of the specified item.
     * @param string $name the name of the item whose children are to be looked for.
     * @param array $childrenList the child list built via [[getChildrenList()]]
     * @param array $result the children and grand children (in array keys)
     */
    protected function getChildrenRecursive($name, $childrenList, &$result)
    {
        if (isset($childrenList[$name])) {
            foreach ($childrenList[$name] as $child) {
                $result[$child] = true;
                $this->getChildrenRecursive($child, $childrenList, $result);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getRule($name)
    {
        if ($this->rules !== null) {
            return isset($this->rules[$name]) ? $this->rules[$name] : null;
        }

        $authRule = $this->getAuthRuleByName($name);
        return $authRule->getDataObject();
    }

    /**
     * {@inheritdoc}
     */
    public function getRules()
    {
        if ($this->rules !== null) {
            return $this->rules;
        }

        /** @var AuthRule[] $authRules */
        $authRules = AuthRule::find()->all();

        $rules = [];
        foreach ($authRules as $authRule) {
            $rules[$authRule->name] = $authRule->getDataObject();
        }

        return $rules;
    }

    /**
     * {@inheritdoc}
     */
    public function getAssignment($roleName, $userId)
    {
        if ($this->isEmptyUserId($userId)) {
            return null;
        }

        $authItem = $this->getAuthItemByName($roleName);
        if (!$authItem) {
            return null;
        }

        /** @var AuthAssignment $authAssignment */
        $authAssignment = AuthAssignment::find()->where(['item_id' => $authItem->item_id, 'user_id' => $userId])->one();
        if (!$authAssignment) {
            return null;
        }

        return new Assignment([
            'userId' => $authAssignment->user_id,
            'roleName' => $authItem->name,
            'createdAt' => $authAssignment->created_at,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function getAssignments($userId)
    {
        if ($this->isEmptyUserId($userId)) {
            return [];
        }

        $authItems = $this->getAuthItems();
        $ids = ArrayHelper::getColumn($authItems, 'item_id');

        /** @var AuthAssignment[] $authAssignments */
        $authAssignments = AuthAssignment::find()->where(['user_id' => $userId, 'item_id' => $ids])->with('authItem')->all();

        $assignments = [];
        foreach ($authAssignments as $authAssignment) {
            $authItem = $authAssignment->authItem;
            $assignments[$authItem->name] = new Assignment([
                'userId' => $authAssignment->user_id,
                'roleName' => $authItem->name,
                'createdAt' => $authAssignment->created_at,
            ]);
        }

        return $assignments;
    }

    /**
     * {@inheritdoc}
     */
    public function canAddChild($parent, $child)
    {
        return !$this->detectLoop($parent, $child);
    }

    /**
     * {@inheritdoc}
     */
    public function addChild($parent, $child)
    {
        if ($parent->name === $child->name) {
            throw new InvalidArgumentException("Cannot add '{$parent->name}' as a child of itself.");
        }

        if ($parent instanceof Permission && $child instanceof Role) {
            throw new InvalidArgumentException('Cannot add a role as a child of a permission.');
        }

        if ($this->detectLoop($parent, $child)) {
            throw new InvalidCallException("Cannot add '{$child->name}' as a child of '{$parent->name}'. A loop has been detected.");
        }

        $authItems = $this->getAuthItems();
        $authItemP = $authItemC = null;
        foreach ($authItems as $authItem) {
            if ($authItem->name == $parent->name) {
                $authItemP = $authItem;
            }
            if ($authItem->name == $child->name) {
                $authItemC = $authItem;
            }
        }
        if (!$authItemP or !$authItemC) {
            throw new InvalidArgumentException("Cannot add '{$child->name}' as a child of '{$parent->name}'. Not in this scope.");
        }

        $isok = AuthItemChild::find()->where(['parent_item_id' => $authItemP->item_id, 'child_item_id' => $authItemC->item_id])->exists();
        if (!$isok) {
            $authItemChild = new AuthItemChild();
            $authItemChild->parent_item_id = $authItemP->item_id;
            $authItemChild->child_item_id = $authItemC->item_id;
            $isok = $authItemChild->save();
            if (!$isok) {
                $errors = $authItemChild->getFirstErrors();
                throw new InvalidArgumentException("Cannot add '{$child->name}' as a child of '{$parent->name}'. " . reset($errors));
            }
        }

        $this->invalidateCache();

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function removeChild($parent, $child)
    {
        $authItems = $this->getAuthItems();
        $authItemP = $authItemC = null;
        foreach ($authItems as $authItem) {
            if ($authItem->name == $parent->name) {
                $authItemP = $authItem;
            }
            if ($authItem->name == $child->name) {
                $authItemC = $authItem;
            }
        }
        if (!$authItemP or !$authItemC) {
            throw new InvalidArgumentException("Cannot remove '{$child->name}' as a child of '{$parent->name}'. Not in this scope.");
        }

        $cnt = AuthItemChild::deleteAll(['parent_item_id' => $authItemP->item_id, 'child_item_id' => $authItemC->item_id]);

        $this->invalidateCache();

        return $cnt > 0 ? true : false;
    }

    /**
     * {@inheritdoc}
     */
    public function removeChildren($parent)
    {
        $authItems = $this->getAuthItems();
        $authItemP = null;
        foreach ($authItems as $authItem) {
            if ($authItem->name == $parent->name) {
                $authItemP = $authItem;
                break;
            }
        }
        if (!$authItemP) {
            throw new InvalidArgumentException("Cannot remove parent '{$parent->name}'. Not in this scope.");
        }

        $cnt = AuthItemChild::deleteAll(['parent_item_id' => $authItemP->item_id]);

        $this->invalidateCache();

        return $cnt > 0 ? true : false;
    }

    /**
     * {@inheritdoc}
     */
    public function hasChild($parent, $child)
    {
        $authItemP = $this->getAuthItemByName($parent->name);
        $authItemC = $this->getAuthItemByName($child->name);
        $isok = AuthItemChild::find()->where(['parent_item_id' => $authItemP->item_id, 'child_item_id' => $authItemC->item_id])->exists();
        return $isok ? true : false;
    }

    /**
     * {@inheritdoc}
     */
    public function getChildren($name)
    {
        $authItem = $this->getAuthItemByName($name);
        $childAuthItems = $authItem->childAuthItems;

        $authItems = $this->getAuthItems();
        $ids = ArrayHelper::getColumn($authItems, 'item_id');

        $children = [];
        foreach ($childAuthItems as $child) {
            if (in_array($child->item_id, $ids)) {
                $children[$child->name] = $child->getItemObject();
            }
        }

        return $children;
    }

    /**
     * Checks whether there is a loop in the authorization item hierarchy.
     * @param Item $parent the parent item
     * @param Item $child the child item to be added to the hierarchy
     * @return bool whether a loop exists
     */
    protected function detectLoop($parent, $child)
    {
        if ($child->name === $parent->name) {
            return true;
        }
        foreach ($this->getChildren($child->name) as $grandchild) {
            if ($this->detectLoop($parent, $grandchild)) {
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function assign($role, $userId)
    {
        $authItem = $this->getAuthItemByName($role->name);
        $assignment = new Assignment([
            'userId' => (string)$userId,
            'roleName' => $role->name,
            'createdAt' => time(),
        ]);

        $authAssignment = new AuthAssignment();
        $authAssignment->item_id = $authItem->item_id;
        $authAssignment->user_id = $assignment->userId;
        $authAssignment->created_at = $assignment->createdAt;
        $authAssignment->save();

        unset($this->_checkAccessAssignments[(string)$userId]);
        return $assignment;
    }

    /**
     * {@inheritdoc}
     */
    public function revoke($role, $userId)
    {
        if ($this->isEmptyUserId($userId)) {
            return false;
        }

        unset($this->_checkAccessAssignments[(string)$userId]);

        $authItem = $this->getAuthItemByName($role->name);
        $cnt = AuthAssignment::deleteAll(['user_id' => (string)$userId, 'item_id' => $authItem->item_id]);
        return $cnt > 0 ? true : false;
    }

    /**
     * {@inheritdoc}
     */
    public function revokeAll($userId)
    {
        if ($this->isEmptyUserId($userId)) {
            return false;
        }

        unset($this->_checkAccessAssignments[(string)$userId]);

        $authItems = $this->getAuthItems();
        $ids = ArrayHelper::getColumn($authItems, 'item_id');
        $cnt = AuthAssignment::deleteAll(['user_id' => (string)$userId, 'item_id' => $ids]);
        return $cnt > 0 ? true : false;
    }

    /**
     * {@inheritdoc}
     */
    public function removeAll()
    {
        $ids = AuthScope::find()->where(['scope_id' => $this->scope])->select('item_id')->column();
        $this->_checkAccessAssignments = [];
        AuthAssignment::deleteAll(['item_id' => $ids]);
        AuthItemChild::deleteAll(['parent_item_id' => $ids, 'child_item_id' => $ids]);
        AuthScope::deleteAll(['scope_id' => $this->scope]);
        AuthItem::deleteAll(['item_id' => $ids]);
        $this->invalidateCache();
    }

    /**
     * {@inheritdoc}
     */
    public function removeAllPermissions()
    {
        $this->removeAllItems(Item::TYPE_PERMISSION);
    }

    /**
     * {@inheritdoc}
     */
    public function removeAllRoles()
    {
        $this->removeAllItems(Item::TYPE_ROLE);
    }

    /**
     * Removes all auth items of the specified type.
     * @param int $type the auth item type (either Item::TYPE_PERMISSION or Item::TYPE_ROLE)
     */
    protected function removeAllItems($type)
    {
        $ids = AuthScope::find()->where(['scope_id' => $this->scope, 'type' => $type])->select('item_id')->column();
        $this->_checkAccessAssignments = [];
        AuthAssignment::deleteAll(['item_id' => $ids]);
        AuthItemChild::deleteAll(['parent_item_id' => $ids, 'child_item_id' => $ids]);
        AuthScope::deleteAll(['scope_id' => $this->scope, 'item_id' => $ids]);
        AuthItem::deleteAll(['item_id' => $ids]);
        $this->invalidateCache();
    }

    /**
     * {@inheritdoc}
     */
    public function removeAllRules()
    {
        $ids = AuthScope::find()->where(['scope_id' => $this->scope])->select('item_id')->column();
        AuthItem::updateAll(['rule_name' => null], ['item_id' => $ids]);

        $this->invalidateCache();
    }

    /**
     * {@inheritdoc}
     */
    public function removeAllAssignments()
    {
        $ids = AuthScope::find()->where(['scope_id' => $this->scope])->select('item_id')->column();
        $this->_checkAccessAssignments = [];
        AuthAssignment::deleteAll(['item_id' => $ids]);
    }

    public function invalidateCache()
    {
        if ($this->cache !== null) {
            $this->cache->delete($this->cacheKey);
            $this->items = null;
            $this->rules = null;
            $this->parents = null;
        }
        $this->_checkAccessAssignments = [];
    }

    public function loadFromCache()
    {
        if ($this->items !== null || !$this->cache instanceof CacheInterface) {
            return;
        }

        $data = $this->cache->get($this->cacheKey);
        if (is_array($data) && isset($data[0], $data[1], $data[2])) {
            list($this->items, $this->rules, $this->parents) = $data;
            return;
        }

        $authItems = $this->getAuthItems();
        $this->items = [];
        foreach ($authItems as $authItem) {
            $this->items[$authItem->name] = $authItem->getItemObject();
        }

        $authRules = $this->getAuthRules();
        $this->rules = [];
        foreach ($authRules as $authRule) {
            $this->rules[$authRule->name] = $authRule->getDataObject();
        }

        $ids = ArrayHelper::getColumn($authItems, 'item_id');
        /** @var AuthItemChild[] $authItemChilds */
        $authItemChilds = AuthItemChild::find()->where(['parent_item_id' => $ids, 'child_item_id' => $ids])
            ->with('parentAuthItem', 'childAuthItem')->all();
        $this->parents = [];
        foreach ($authItemChilds as $row) {
            $authItem = $row->childAuthItem;
            if (isset($this->items[$authItem->name])) {
                $this->parents[$authItem->name][] = $row->parentAuthItem->name;
            }
        }

        $this->cache->set($this->cacheKey, [$this->items, $this->rules, $this->parents]);
    }

    /**
     * Returns all role assignment information for the specified role.
     * @param string $roleName
     * @return string[] the ids. An empty array will be
     * returned if role is not assigned to any user.
     */
    public function getUserIdsByRole($roleName)
    {
        if (empty($roleName)) {
            return [];
        }

        $authItem = $this->getAuthItemByName($roleName);
        return AuthAssignment::find()->where(['item_id' => $authItem->item_id])->select('user_id')->column();
    }

    /**
     * Check whether $userId is empty.
     * @param mixed $userId
     * @return bool
     */
    private function isEmptyUserId($userId)
    {
        return !isset($userId) || $userId === '';
    }
}
