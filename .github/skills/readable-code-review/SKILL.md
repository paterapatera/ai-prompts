---
name: readable-code-review
description: 指定されたディレクトリまたはファイル群に対してテンプレートに基づく一貫したコードレビューを自動生成し、.review/results/ に連番で出力します。初期スクリーニングや複数ファイルの迅速な品質確認に最適です。
---

# Readable Code Review AgentSkill

## 概要

このスキルは、指定されたディレクトリまたはファイルリストに対して自動的にコードレビューを生成します。`.review/results/` ディレクトリに連番で構造化されたレビュー結果を出力します。

## 機能

- **.review/results/ディレクトリの初期化**: 既存の結果ディレクトリを削除して、クリーンな状態から開始
- **複数ファイルの一括処理**: ユーザー指定のターゲットファイルを順序立てて処理
- **連番でのレビュー生成**: 各ファイルに対して`01_*.md`, `02_*.md` のような連番形式でレビューを生成
- **テンプレートベースの標準化**: 統一されたテンプレートを使用した一貫性のあるレビュー形式

## 使用方法

### 基本構文

```bash
python3 scripts/generate_review.py <ファイルパス> <出力ファイルパス>
```

### パラメータ

- `<ファイルパス>`: レビュー対象のファイルパス
- `<出力ファイルパス>`: 出力するレビューファイルのパス

### 例

```bash
# 単一ファイルのレビュー生成
python3 scripts/generate_review.py src/main.py .review/results/01_main.md

# 複数ファイルのレビュー生成
python3 scripts/generate_review.py src/utils.py .review/results/02_utils.md
python3 scripts/generate_review.py tests/test.py .review/results/03_test.md
```

## 出力形式

レビュー結果は `.review/results/` に以下の形式で生成されます：

```
.review/results/
├── 01_main.md
├── 02_utils.md
├── 03_test.md
└── ...
```

各レビューファイルはテンプレートに基づいた構造を持ちます。

## 設定

設定ファイルは `assets/config-template.json` を参考に、プロジェクトルートに `review-config.json` を作成してカスタマイズできます。

## 使用パターン

### シンプルなワンファイルレビュー

```bash
# .review/results/ ディレクトリを削除
rm -rf .review/results

# 新規にディレクトリを作成
mkdir -p .review/results

# レビュー生成
python3 .github/skills/readable-code-review/scripts/generate_review.py src/main.py .review/results/01_main.md
```

### 複数ファイルの一括処理

シェルスクリプトなどで、複数ファイルに対して連番形式で実行します：

```bash
#!/bin/bash
rm -rf .review/results
mkdir -p .review/results

counter=1
for file in src/main.py src/utils.py tests/test.py; do
    seq=$(printf "%02d" $counter)
    basename=$(basename "$file" | cut -d. -f1)
    python3 .github/skills/readable-code-review/scripts/generate_review.py "$file" ".review/results/${seq}_${basename}.md"
    ((counter++))
done
```

## 依存関係

- Python 3.7+
- テンプレートファイル: `.review/templates/review.md`
