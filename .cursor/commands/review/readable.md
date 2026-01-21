# リーダブルコードレビュー

## 概要

コードの可読性、保守性、理解しやすさに焦点を当てたコードレビューを実施します。変数名、関数名、コード構造、コメント、複雑度などを評価し、改善提案を行います。

## パラメータ

| パラメータ | 型     | 必須   | 説明                                                                |
| ---------- | ------ | ------ | ------------------------------------------------------------------- |
| filepath   | string | いいえ | レビュー対象のファイルパス                                          |
| diff       | string | いいえ | `diff`を指定すると、gitの差分があるファイルのみをレビュー対象とする |

## 手順

1. **レビュータスクの作成**
   - `diff`フラグが指定されていない場合：
     - 連番を決定：`.review/results/` 内の既存の `readable-review-{数字}.md` ファイルの最大数字 + 1（存在しない場合は1）
     - `python3 .review/generate_review.py {filepath} ".review/results/readable-review-{連番}.md"`でレビュータスクを作成します。
   - `diff`フラグが指定されている場合：
     - `git status --porcelain | grep -E '^(M|A|\?\?)' | awk '{print $2}'`を実行して、変更されたファイルと新規作成ファイルのリストを取得する（ステージ済みと未ステージの両方の変更を含む）
     - 連番を決定：`.review/results/` 内の既存の `readable-review-{数字}.md` ファイルの最大数字 + 1（存在しない場合は1）
     - 取得した各ファイルに対して`python3 .review/generate_review.py {file} ".review/results/readable-review-{連番}.md"`でレビュータスクを作成します（各ファイルごとに連番をインクリメント）。

## 使用例

### 基本的な使用例（ファイルを指定してレビュー）

```
/review/readable src/main.py
```

### git差分があるファイルのみをレビュー

```
/review/readable diff
```
