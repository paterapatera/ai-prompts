#!/usr/bin/env python3
import sys
import os

def main():
    if len(sys.argv) != 3:
        print("Usage: python generate_review.py <target_file_path> <output_file_path>")
        sys.exit(1)

    target_file_path = sys.argv[1]
    output_file_path = sys.argv[2]

    # テンプレートファイルのパス
    template_path = ".review/templates/review.md"

    # テンプレートが存在するか確認
    if not os.path.exists(template_path):
        print(f"Error: Template file '{template_path}' not found.")
        sys.exit(1)

    # テンプレートを読み込む
    with open(template_path, 'r', encoding='utf-8') as f:
        template_content = f.read()

    # {対象ファイルパス} を置き換える
    output_content = template_content.replace("{対象ファイルパス}", target_file_path)

    # 出力先ディレクトリが存在するか確認し、なければ作成
    output_dir = os.path.dirname(output_file_path)
    if output_dir and not os.path.exists(output_dir):
        os.makedirs(output_dir)

    # 出力ファイルに書き出す
    with open(output_file_path, 'w', encoding='utf-8') as f:
        f.write(output_content)

    print(f"Review file generated: {output_file_path}")

if __name__ == "__main__":
    main()