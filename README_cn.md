# AnWikiArticleReview（中文说明）

MediaWiki 扩展：**新条目投稿与编辑资格审核**。

普通注册用户**不能**使用标准编辑器直接建页。流程为：

1. 选择新页面标题（`Special:ChooseArticleTitle`）
2. 填写条目正文（`Special:SubmitArticle`）
3. 提交审核
4. 等待审核员批准或驳回

批准后扩展会：

- 以**投稿用户**为第一版作者创建正式页面
- 将投稿用户加入可配置的 **approved（获准编辑）** 用户组
- 记录审核员与审计事件
- 可选：通过 MediaWiki 核心邮件系统通知管理员

本扩展**只处理新建页面审核**，不处理已有页面的修改审核。

英文文档见 [README.md](README.md)。完整配置示例见 [LocalSettings.example.php](LocalSettings.example.php)。

---

## 功能列表

- 两步投稿：先选标题，再写正文
- 每个用户仅一条主投稿（`UNIQUE submitter`）
- 每个规范化标题仅一条主投稿（`UNIQUE namespace + dbkey`）
- 正文版本只增不改（重新提交不会在审核列表产生重复行）
- 状态机：待审核 / 已批准 / 已驳回 / 已撤回 / 标题冲突
- 审核员界面：列表、预览、批准、驳回
- 管理员邮件通知状态与手动重试
- 异步邮件（JobQueue + MediaWiki `UserMailer`，不自带 SMTP 客户端）
- 可配置标题提示（纯文本，HTML 转义）
- 用户菜单入口；不存在页面提示投稿
- 国际化：英文、简体中文、繁体中文

---

## 默认开启了什么？（重要）

加载扩展后（`wfLoadExtension( 'AnWikiArticleReview' )`），下列项**默认已生效或默认开启**；未单独列出的布尔项请看下表。

### 默认已开启 / 已生效

| 项目 | 默认值 | 说明 |
|------|--------|------|
| 允许投稿命名空间 | 仅主命名空间 `NS_MAIN`（`0`） | `$wgAnWikiArticleReviewAllowedNamespaces` |
| 批准后加入用户组 | `approved` | `$wgAnWikiArticleReviewApprovedGroup` |
| 批准后自动晋升组 | **开启** `true` | `$wgAnWikiArticleReviewPromoteOnApprove` |
| 允许重新提交 | **开启** `true` | 驳回 / 撤回 / 冲突后可改稿再交 |
| 允许撤回 | **开启** `true` | 待审核稿可撤回 |
| 不存在页面上显示投稿入口 | **开启** `true` | 未获准用户访问缺失页时显示提示 |
| 正文最小长度 | 100 字节 | |
| 正文最大长度 | 2097152 字节（2 MiB） | |
| 摘要最大长度 | 500 字节 | |
| 扩展权限（用户组） | 见下文「默认权限」 | 随扩展注册，无需再写即可生效 |
| 特殊页面 | 五个 Special 页均可用 | 按权限访问 |
| 导航菜单「创建新条目 / 我的审核稿」 | 对有投稿权且**未**在获准组的用户显示 | Hook 默认工作 |
| 数据库表 | 运行 `update.php` 后创建 | 需手动执行更新脚本 |

### 默认关闭 / 为空（需你自行配置才生效）

| 项目 | 默认值 | 说明 |
|------|--------|------|
| **邮件通知总开关** | **关闭** `false` | 不发邮件，除非你打开 |
| 邮件收件人列表 | `[]` 空 | 即使打开总开关，无收件人也不发 |
| 邮件正文摘要 | **关闭** `false` | 默认邮件不含投稿正文 |
| 要求已确认邮箱才能投稿 | **关闭** `false` | 预留项，当前逻辑未强制拦截 |
| 标题提示文案 | `''` 空 | 空则使用 i18n 默认提示 |
| 标题输入框 placeholder | `''` 空 | 空则使用 i18n 默认占位文字 |
| MediaWiki 核心 SMTP | **不属于本扩展** | 需站点自行配置 `$wgEnableEmail`、`$wgSMTP` 等 |

### 邮件事件默认列表（仅在邮件总开关打开且有收件人时有效）

默认会通知的事件类型：

```php
[ 'submit', 'resubmit' ]  // 首次提交、重新提交
```

**默认不会**因 `approve` / `reject` / `conflict` 发信（需自行加入 `$wgAnWikiArticleReviewNotificationEvents`）。

### 默认权限（扩展自带，加载后即生效）

| 权限 | 默认拥有的组 | 作用 |
|------|--------------|------|
| `article-review-submit` | `user`（所有注册用户） | 投稿、查看自己的审核稿 |
| `article-review-review` | `article-reviewer`、`sysop` | 审核列表、批准、驳回 |
| `article-review-admin` | `sysop` | 邮件通知管理、状态重置等 |

**扩展默认不会**给 `user` 组授予核心 `edit` / `createpage`。  
若站点仍允许普通用户编辑，需在 `LocalSettings.php` 中自行关闭（见下文「权限配置」）；否则资格审核模型不完整。

### 一句话总结

- **投稿 / 审核 / 重提 / 撤回 / 批准晋升 / 缺页入口**：默认可用（权限与开关如上）。
- **邮件**：默认**关**；打开后默认只通知「提交」和「重新提交」，且要自己填收件人并配置 MediaWiki SMTP。
- **普通用户不能直接编辑**：扩展不代你关，必须在 `LocalSettings.php` 里自己关。

---

## 系统要求

| 组件 | 版本 |
|------|------|
| MediaWiki | 1.43+ |
| PHP | 8.1+ |
| 数据库 | MariaDB 10.5+ / MySQL / SQLite / PostgreSQL |

---

## 安装

1. 将本目录放到 `extensions/AnWikiArticleReview`。

2. 在 `LocalSettings.php` 中加入：

```php
wfLoadExtension( 'AnWikiArticleReview' );
```

3. 配置权限（见下）及扩展选项。

4. 运行数据库更新：

```bash
php maintenance/run.php update
```

连续执行两次是安全的（幂等）。

5. 将审核员加入 `article-reviewer` 用户组（或使用已有 `sysop`）。

完整示例见 `LocalSettings.example.php`。

---

## 升级

1. 替换扩展文件。
2. 执行 `php maintenance/run.php update`。
3. 查看 `CHANGELOG.md`。
4. 如有需要：`php maintenance/run.php rebuildLocalisationCache`。

---

## 卸载

1. 从 `LocalSettings.php` 移除 `wfLoadExtension( 'AnWikiArticleReview' )` 及相关配置。
2. 可选：删除数据表（不可恢复）：

```sql
DROP TABLE IF EXISTS anwiki_article_review_notification;
DROP TABLE IF EXISTS anwiki_article_review_event;
DROP TABLE IF EXISTS anwiki_article_review_revision;
DROP TABLE IF EXISTS anwiki_article_review_submission;
```

3. 删除扩展目录。

---

## 权限配置

推荐模型（需写在 `LocalSettings.php`，**不是**扩展默认值）：

```php
// 匿名：只读
$wgGroupPermissions['*']['edit'] = false;
$wgGroupPermissions['*']['createpage'] = false;
$wgGroupPermissions['*']['createtalk'] = false;

// 普通注册用户：不能直接编辑
$wgGroupPermissions['user']['edit'] = false;
$wgGroupPermissions['user']['createpage'] = false;
$wgGroupPermissions['user']['createtalk'] = false;

// 获准编辑者（批准投稿后自动加入）
$wgGroupPermissions['approved']['edit'] = true;
$wgGroupPermissions['approved']['createpage'] = true;
$wgGroupPermissions['approved']['createtalk'] = true;
$wgGroupPermissions['approved']['writeapi'] = true;
```

### 用户组管理（推荐）

```php
$wgAddGroups['sysop'][] = 'article-reviewer';
$wgRemoveGroups['sysop'][] = 'article-reviewer';
$wgAddGroups['bureaucrat'][] = 'approved';
$wgRemoveGroups['bureaucrat'][] = 'approved';
```

扩展侧：

```php
$wgAnWikiArticleReviewApprovedGroup = 'approved';      // 默认即为 approved
$wgAnWikiArticleReviewPromoteOnApprove = true;         // 默认 true
```

---

## 特殊页面

| 页面 | 使用者 | 用途 |
|------|--------|------|
| `Special:ChooseArticleTitle` | 投稿者 | 选择并校验标题 |
| `Special:SubmitArticle/<标题>` | 投稿者 | 正文、预览、提交 |
| `Special:MyArticleSubmission` | 投稿者 | 自己的稿件、重提、撤回 |
| `Special:ArticleReview` | 审核员 | 列表与处理 |
| `Special:ReviewNotifications` | 管理员 | 邮件状态 / 重试 |

审核员路由：

- `Special:ArticleReview` / `…/pending`
- `…/rejected`、`…/approved`、`…/conflict`、`…/all`
- `…/view/{编号}`
- `…/notifications` → 跳转到 `Special:ReviewNotifications`

---

## 标题选择页

用户入口：**Special:ChooseArticleTitle**。

### 标题提示配置

```php
// 输入框下方说明（纯文本；始终 HTML 转义，不当作维基文本解析）
$wgAnWikiArticleReviewTitleHint =
    '请输入支付卡的正式名称，例如“中国农业银行金穗借记卡”。';

// 可选：输入框 placeholder（与 hint 不是同一项）
$wgAnWikiArticleReviewTitlePlaceholder =
    '输入准备创建的页面名称';
```

规则：

- 提示**不会**按维基文本或 HTML 解析
- 配置为空时使用 i18n 消息 `anwikiarticlereview-title-hint`
- 不能通过 URL 参数覆盖提示
- 标题选择页只做**预检查**，**不会**在数据库中占用标题

---

## 投稿与审核流程

```text
选标题 → 写正文提交 → PENDING（待审核）
    → 批准 → 创建页面 + 加入获准组 → APPROVED
    → 驳回 → REJECTED → 用户可改稿重提 → PENDING
    → 撤回 → WITHDRAWN → 用户可改稿重提 → PENDING
    → 批准时正式页已存在 → CONFLICT（冲突）
```

批准与驳回必须 **POST + CSRF**，不能用 GET 改状态。

---

## 防重复机制

三层保护：

1. 写入前的应用层检查  
2. 数据库唯一索引  
   - `UNIQUE (aars_submitter_user_id)`  
   - `UNIQUE (aars_namespace, aars_title)`  
3. 审核列表**只读主投稿表**（一行一条主投稿；版本表单独存）

---

## 邮件通知

### 基本原则

> **AnWikiArticleReview 不直接配置 SMTP。**  
> 扩展使用 MediaWiki 的邮件系统。网站运维应在 `LocalSettings.php` 中配置 `$wgEnableEmail`、`$wgSMTP`、`$wgPasswordSender` 等。

### 扩展通知配置

```php
// 默认 false；要发信必须显式打开
$wgAnWikiArticleReviewEmailNotifications = true;

$wgAnWikiArticleReviewNotificationRecipients = [
    'review@example.org',
];

// 默认已是 submit + resubmit；可按需追加 approve / reject / conflict
$wgAnWikiArticleReviewNotificationEvents = [
    'submit',
    'resubmit',
];

$wgAnWikiArticleReviewEmailSubjectPrefix = '[支付卡百科新条目审核]';
$wgAnWikiArticleReviewEmailIncludeContentExcerpt = false;  // 默认 false
$wgAnWikiArticleReviewEmailContentExcerptLength = 300;
```

### 异步发送

1. 投稿 / 审核事务提交成功  
2. 创建通知记录（幂等唯一键）  
3. 将 `SendReviewNotificationJob` 放入 JobQueue  
4. 后台通过 `UserMailer` 发送并更新状态  

邮件失败**不会**回滚投稿。

### MediaWiki 核心 SMTP 示例

```php
$wgEnableEmail = true;
$wgEmergencyContact = 'wiki@example.org';
$wgPasswordSender = 'wiki@example.org';

$smtpPassword = getenv( 'MEDIAWIKI_SMTP_PASSWORD' );
if ( $smtpPassword === false || $smtpPassword === '' ) {
    throw new RuntimeException( 'MEDIAWIKI_SMTP_PASSWORD is not configured' );
}

$wgSMTP = [
    'host'      => 'tls://smtp.example.org',
    'IDHost'    => 'example.org',
    'localhost' => 'example.org',
    'port'      => 587,
    'auth'      => true,
    'username'  => 'wiki@example.org',
    'password'  => $smtpPassword,
];
```

**生产环境必须替换示例域名与邮箱。**

#### `$wgSMTP` 字段说明

| 键 | 含义 |
|----|------|
| `host` | SMTP 主机，可含 `tls://` 或 `ssl://` |
| `IDHost` | Message-ID 用主机名 |
| `localhost` | HELO/EHLO 名称 |
| `port` | 常用 587（submission）或 465（隐式 TLS） |
| `auth` | 是否认证 |
| `username` | SMTP 用户名 |
| `password` | SMTP 密码（请用环境变量，勿提交到 Git） |

说明：

- **587** 常用于 STARTTLS 提交  
- **465** 常用于隐式 TLS  
- 以邮件服务商文档为准  
- `$wgPasswordSender` 通常需与已验证发件地址一致  
- 服务器需允许 PHP/Web 进程访问 SMTP  
- 部分服务商要求应用专用密码  

### 环境变量与密码安全

**应当：**

- 用 `getenv( 'MEDIAWIKI_SMTP_PASSWORD' )` 或密钥管理读取密码  
- 密钥不放 Web 可访问目录、不进 Git  

**禁止：**

- 把生产 SMTP 密码提交到 Git  
- 把密码写进扩展代码  
- 把凭据放在公开 Wiki 页面  
- 在错误页输出密码  
- 把 `.env` 放在可被 Web 访问的目录  

### JobQueue

邮件由任务队列发送，请确保任务会执行：

```bash
php maintenance/run.php runJobs
```

小型站点也可配置 `$wgJobRunRate` 或常驻 runner。

### 测试邮件

```bash
php maintenance/run.php \
    extensions/AnWikiArticleReview/maintenance/SendTestReviewEmail.php \
    --to=admin@example.org
```

未指定 `--to` 时，使用 `$wgAnWikiArticleReviewNotificationRecipients` 的第一个地址。

脚本仅 CLI 运行，使用核心邮件，不打印 SMTP 密码，失败时以非零退出码退出。

---

## 邮件故障排查

| 检查项 | 确认内容 |
|--------|----------|
| 邮件功能 | `$wgEnableEmail === true` |
| 扩展总开关 | `$wgAnWikiArticleReviewEmailNotifications === true` |
| 收件人 | `$wgAnWikiArticleReviewNotificationRecipients` 非空 |
| 事件类型 | 在 `$wgAnWikiArticleReviewNotificationEvents` 中 |
| 发件地址 | `$wgPasswordSender` 与服务商验证地址一致 |
| SMTP 主机/端口 | 正确，TLS 方案匹配 |
| TLS 证书 | PHP CA 包有效 |
| 防火墙 | 出站 587/465 放行 |
| JobQueue | 任务在运行 |
| 失败列表 | `Special:ReviewNotifications` |
| 日志 | MediaWiki / Web 服务器日志 |
| 垃圾箱 | 检查收件人垃圾邮件 |
| DNS | 发件域 SPF、DKIM、DMARC |

---

## 配置项完整参考

| 变量 | 默认值 | 说明 |
|------|--------|------|
| `$wgAnWikiArticleReviewAllowedNamespaces` | `[ 0 ]`（主命名空间） | 允许投稿的命名空间 |
| `$wgAnWikiArticleReviewApprovedGroup` | `'approved'` | 批准后加入的用户组 |
| `$wgAnWikiArticleReviewPromoteOnApprove` | `true` | 批准后自动加组 |
| `$wgAnWikiArticleReviewTitleHint` | `''` | 标题说明文字（纯文本） |
| `$wgAnWikiArticleReviewTitlePlaceholder` | `''` | 标题输入框占位符 |
| `$wgAnWikiArticleReviewMinContentBytes` | `100` | 正文最小字节数 |
| `$wgAnWikiArticleReviewMaxContentBytes` | `2097152` | 正文最大字节数 |
| `$wgAnWikiArticleReviewMaxSummaryBytes` | `500` | 摘要最大字节数 |
| `$wgAnWikiArticleReviewAllowResubmit` | `true` | 允许重新提交 |
| `$wgAnWikiArticleReviewAllowWithdraw` | `true` | 允许撤回 |
| `$wgAnWikiArticleReviewRequireConfirmedEmail` | `false` | 要求已确认邮箱（预留） |
| `$wgAnWikiArticleReviewShowLinkOnMissingPages` | `true` | 缺页显示投稿入口 |
| `$wgAnWikiArticleReviewEmailNotifications` | `false` | 邮件通知总开关 |
| `$wgAnWikiArticleReviewNotificationRecipients` | `[]` | 管理员收件邮箱列表 |
| `$wgAnWikiArticleReviewNotificationEvents` | `['submit','resubmit']` | 触发通知的事件 |
| `$wgAnWikiArticleReviewEmailSubjectPrefix` | `'[AnWikiArticleReview]'` | 邮件主题前缀 |
| `$wgAnWikiArticleReviewEmailIncludeContentExcerpt` | `false` | 是否附带正文摘要 |
| `$wgAnWikiArticleReviewEmailContentExcerptLength` | `300` | 摘要最大长度 |

SMTP **不是**扩展配置项，请使用核心 `$wgSMTP`。

---

## 数据库表

| 表名 | 用途 |
|------|------|
| `anwiki_article_review_submission` | 主投稿（用户唯一 + 标题唯一） |
| `anwiki_article_review_revision` | 正文版本（只追加） |
| `anwiki_article_review_event` | 审核审计事件 |
| `anwiki_article_review_notification` | 邮件发送记录（幂等） |

Schema：`sql/tables.json`、`sql/mysql/`、`sql/sqlite/`、`sql/postgres/`。

---

## 日志

- 邮件失败：通知表 `aarn_last_error`（已脱敏）  
- 任务错误：MediaWiki Job 日志  
- 请勿记录 SMTP 密码或完整认证报文  

---

## 隐私说明

- 普通用户看不到他人投稿  
- 标题占用提示不泄露投稿者与状态  
- 管理员通知页面对邮箱做部分遮罩  
- 默认邮件不含投稿者邮箱、IP 与完整正文  
- 通知表需 `article-review-admin` 权限  

---

## 开发与测试

架构见 `PLAN.md` 与 `src/` 目录。

```bash
php tests/phpunit/phpunit.php \
  extensions/AnWikiArticleReview/tests/phpunit/unit
```

手工检查建议：匿名不能投稿、hint 转义 HTML、同用户/同标题不能双开、重提不重复列表行、双重点批准只建一页、邮件 Job 幂等、测试邮件脚本可用。

---

## 许可证

GPL-2.0-or-later，见 `LICENSE`。

---

## 更新日志

见 `CHANGELOG.md`。
