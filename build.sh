#!/usr/bin/env bash
#
# build.sh — 打包 ultimate-ecommerce 客戶安裝包，取代原本 CLAUDE.md 記載的人工 zip 指令。
#
# 用途：從外掛根目錄（本檔案所在位置）產生給客戶的 ultimate-ecommerce.zip，輸出到
#   /Volumes/work/外掛開發/ultimate-ecommerce.zip
#
# 排除項目：
#   - .git/                                  版本控制內部資料，不該出現在安裝包
#   - *.DS_Store                              macOS 系統雜訊檔（模式要涵蓋子目錄：原本寫成
#                                             "ultimate-ecommerce/.DS_Store" 只擋得到最上層那一個，
#                                             assets/.DS_Store 就這樣被打進過客戶安裝包）
#   - license-server/                         2026-09 外掛端授權系統整個移除後，這個目錄
#                                             已無程式碼呼叫，純粹是還留在磁碟上的孤兒目錄；
#                                             exclude 規則保留，避免不小心把 licenses.json
#                                             （客戶序號清單）打包出去
#   - CLAUDE.md / 任何 *.md                    內部開發文件，含商業邏輯與踩坑細節
#   - build.sh                                打包腳本本身不需要隨安裝包分發
#   - update-config.json / release.sh /       自架更新通道（見 CLAUDE.md「自架更新通道」一節）
#     add-github-token.sh                     的內部工具，只給本機發版流程與 GitHub API 用，
#                                             不該出現在裝到客戶站的安裝包裡
#
# 【為什麼打包後一定要驗證，而不是打包完就結束】
# exclude 清單只是「打包指令當下」的防呆，未來這支腳本被複製/修改、或 zip 指令本身
# 打錯字漏掉一項 --exclude，都不會有任何警訊——因此打包完成後必須實際打開 zip 內容清單、
# 逐一確認關鍵字真的不在裡面，而不是「相信這行指令當初寫對了」。license-server/（授權
# 伺服器驗證邏輯，雖已無外掛程式碼呼叫，仍不該出現在安裝包）與 CLAUDE.md（內部開發文件，
# 可能寫著商業邏輯/踩坑細節，不適合給客戶）外流是需要攔下的問題，列入驗證關鍵字。
#
# 用法：
#   ./build.sh
#
# /Volumes/work/ 是外接／網路磁碟，可能沒有掛載——腳本會先確認輸出目錄存在，
# 找不到時給出明確錯誤訊息並結束，不會讓 zip 指令本身噴出難懂的錯誤。

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR_NAME="$(basename "$SCRIPT_DIR")"
PARENT_DIR="$(dirname "$SCRIPT_DIR")"

OUTPUT_DIR="/Volumes/work/外掛開發"
OUTPUT_ZIP="$OUTPUT_DIR/ultimate-ecommerce.zip"

# --- 前置檢查：輸出目錄是否存在（/Volumes/work/ 可能未掛載） -----------------------
if [ ! -d "$OUTPUT_DIR" ]; then
    echo "錯誤：找不到輸出目錄 \"$OUTPUT_DIR\"。" >&2
    echo "請確認 /Volumes/work/ 磁碟已掛載，再重新執行本腳本。" >&2
    exit 1
fi

echo "打包目錄：$SCRIPT_DIR"
echo "輸出檔案：$OUTPUT_ZIP"

# --- 打包 -------------------------------------------------------------------------
# 從外掛的上層目錄執行 zip，讓 zip 內容以 "ultimate-ecommerce/xxx" 為相對路徑，符合客戶端安裝時
# 直接解壓縮進 wp-content/plugins/ 的預期結構。
cd "$PARENT_DIR"

rm -f "$OUTPUT_ZIP"

zip -r -q "$OUTPUT_ZIP" "$PLUGIN_DIR_NAME" \
    --exclude "$PLUGIN_DIR_NAME/.git/*" \
    --exclude "*.DS_Store" \
    --exclude "$PLUGIN_DIR_NAME/license-server/*" \
    --exclude "$PLUGIN_DIR_NAME/CLAUDE.md" \
    --exclude "$PLUGIN_DIR_NAME/*.md" \
    --exclude "$PLUGIN_DIR_NAME/build.sh" \
    --exclude "$PLUGIN_DIR_NAME/.gitignore" \
    --exclude "$PLUGIN_DIR_NAME/.dev-tools/*" \
    --exclude "$PLUGIN_DIR_NAME/update-config.json" \
    --exclude "$PLUGIN_DIR_NAME/release.sh" \
    --exclude "$PLUGIN_DIR_NAME/add-github-token.sh"

# --- 打包後強制驗證：確認關鍵不該存在的內容真的沒有打包進去 -------------------------
# 任何一個關鍵字出現在 zip 內容清單裡，都代表 exclude 清單失效，必須視為打包失敗、
# 刪除產出的 zip（避免留下一個有問題的檔案被誤用/誤傳給客戶），並用非 0 狀態碼結束。
BAD_KEYWORDS=("license-server" "CLAUDE.md" "\.git/" "\.gitignore" "\.dev-tools" "\.DS_Store" "update-config\.json" "release\.sh" "add-github-token\.sh")

ZIP_LISTING="$(unzip -l "$OUTPUT_ZIP")"

for keyword in "${BAD_KEYWORDS[@]}"; do
    if echo "$ZIP_LISTING" | grep -qE "$keyword"; then
        echo "" >&2
        echo "======================================================================" >&2
        echo "打包驗證失敗：zip 內容中發現不該存在的關鍵字「${keyword}」。" >&2
        echo "這代表 exclude 清單漏掉了某個項目——若不攔下，客戶拿到的安裝包可能含有" >&2
        echo "授權伺服器孤兒目錄（license-server）或內部開發文件（CLAUDE.md）。" >&2
        echo "======================================================================" >&2
        rm -f "$OUTPUT_ZIP"
        echo "已刪除有問題的產出檔案：$OUTPUT_ZIP" >&2
        exit 1
    fi
done

# --- 驗證通過，輸出結果 -------------------------------------------------------------
ZIP_SIZE="$(du -h "$OUTPUT_ZIP" | cut -f1)"

echo ""
echo "打包完成且驗證通過：${OUTPUT_ZIP}（${ZIP_SIZE}）"
