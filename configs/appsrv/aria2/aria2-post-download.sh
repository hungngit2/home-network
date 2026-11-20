#!/usr/bin/env bash
# ==============================================================================
# Aria2 Post-Download Hook
# Automatically moves completed movies/videos to nasdata media/movies & sets 777
# ==============================================================================

DOWNLOADS_DIR="/mnt/nasdata/downloads"
[[ ! -d "$DOWNLOADS_DIR" && -d "/nasdata/downloads" ]] && DOWNLOADS_DIR="/nasdata/downloads"

MOVIES_DIR="/mnt/nasdata/media/movies"
[[ ! -d "$MOVIES_DIR" && -d "/nasdata/media/movies" ]] && MOVIES_DIR="/nasdata/media/movies"

mkdir -p "$MOVIES_DIR" "$DOWNLOADS_DIR" 2>/dev/null || true

VIDEO_EXT_REGEX='\.(mkv|mp4|avi|mov|wmv|flv|m4v|webm|ts|m2ts|rmvb|vob|mpg|mpeg|iso)$'

# aria2 hook passes: $1=GID, $2=num_files, $3=file_path
FILE_PATH="${3:-}"

if [[ -n "$FILE_PATH" && -e "$FILE_PATH" ]]; then
    REAL_PATH="$(realpath "$FILE_PATH" 2>/dev/null || readlink -f "$FILE_PATH" 2>/dev/null || echo "$FILE_PATH")"
    REAL_DOWNLOADS="$(realpath "$DOWNLOADS_DIR" 2>/dev/null || readlink -f "$DOWNLOADS_DIR" 2>/dev/null || echo "$DOWNLOADS_DIR")"

    if [[ "$REAL_PATH" == "$REAL_DOWNLOADS"/* ]]; then
        REL_PATH="${REAL_PATH#$REAL_DOWNLOADS/}"
        TOP_ITEM="${REL_PATH%%/*}"
        TOP_PATH="$REAL_DOWNLOADS/$TOP_ITEM"

        if [[ -d "$TOP_PATH" ]]; then
            # Directory / multi-file torrent: move folder if it contains video files
            if find "$TOP_PATH" -type f | grep -E -i "$VIDEO_EXT_REGEX" | grep -q .; then
                mv -f "$TOP_PATH" "$MOVIES_DIR/" 2>/dev/null || true
            fi
        elif [[ -f "$TOP_PATH" ]]; then
            # Single file: move video and any accompanying subtitle files
            if echo "$TOP_PATH" | grep -E -i -q "$VIDEO_EXT_REGEX"; then
                mv -f "$TOP_PATH" "$MOVIES_DIR/" 2>/dev/null || true
                BASE_NAME="${TOP_PATH%.*}"
                for sub in "${BASE_NAME}".*; do
                    if [[ -f "$sub" ]] && echo "$sub" | grep -E -i -q '\.(srt|ass|sub|vtt|idx)$'; then
                        mv -f "$sub" "$MOVIES_DIR/" 2>/dev/null || true
                    fi
                done
            fi
        fi
    fi
fi

# Ensure open permissions on downloads and media folders
chown -R nobody:nogroup "$DOWNLOADS_DIR" "$MOVIES_DIR" 2>/dev/null || true
chmod -R 777 "$DOWNLOADS_DIR" "$MOVIES_DIR" 2>/dev/null || true