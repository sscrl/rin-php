import Editor from '@monaco-editor/react';
import { editor } from 'monaco-editor';
import React, { useEffect, useRef, useState } from "react";
import { useTranslation } from "react-i18next";
import Loading from 'react-loading';
import { FlatInset, FlatTabButton } from "@rin/ui";
import { useAlert } from "./dialog";
import { useColorMode } from "../utils/darkModeUtils";
import { buildMarkdownImage, uploadImageFile } from "../utils/image-upload";
import { Markdown } from "./markdown";

interface MarkdownEditorProps {
  content: string;
  setContent: (content: string) => void;
  placeholder?: string;
  height?: string;
}

function ToolButton({
  icon,
  label,
  onClick,
  active = false,
}: {
  icon: string;
  label: string;
  onClick: () => void;
  active?: boolean;
}) {
  return (
    <button
      type="button"
      title={label}
      aria-label={label}
      onClick={onClick}
      className={`inline-flex h-8 w-8 items-center justify-center rounded-lg text-base transition-colors hover:bg-black/5 dark:hover:bg-white/10 ${active ? "bg-black/5 text-theme dark:bg-white/10" : "t-primary"}`}
    >
      <i className={icon} aria-hidden="true" />
    </button>
  );
}

export function MarkdownEditor({ content, setContent, placeholder = "> Write your content here...", height = "400px" }: MarkdownEditorProps) {
  const { t } = useTranslation();
  const colorMode = useColorMode();
  const editorRef = useRef<editor.IStandaloneCodeEditor>();
  const imageInputRef = useRef<HTMLInputElement>(null);
  const isComposingRef = useRef(false);
  const [preview, setPreview] = useState<'edit' | 'preview' | 'comparison'>('edit');
  const [uploading, setUploading] = useState(false);
  const [fullscreen, setFullscreen] = useState(false);
  const [imagePanel, setImagePanel] = useState(false);
  const [imageUrl, setImageUrl] = useState("");
  const { showAlert, AlertUI } = useAlert();
  const editorHeight = fullscreen ? 'calc(100vh - 5.75rem)' : height;

  const insertText = (text: string) => {
    const instance = editorRef.current;
    if (!instance) return;
    const selection = instance.getSelection();
    if (!selection) return;
    instance.executeEdits("toolbar", [{ range: selection, text, forceMoveMarkers: true }]);
    instance.focus();
  };

  const wrapSelection = (before: string, after: string, placeholderText = '') => {
    const instance = editorRef.current;
    const model = instance?.getModel();
    const selection = instance?.getSelection();
    if (!instance || !model || !selection) return;
    const selected = model.getValueInRange(selection);
    const inner = selected || placeholderText;
    instance.executeEdits("toolbar", [{
      range: selection,
      text: `${before}${inner}${after}`,
      forceMoveMarkers: true,
    }]);
    instance.focus();
  };

  const prefixLines = (prefix: string) => {
    const instance = editorRef.current;
    const model = instance?.getModel();
    const selection = instance?.getSelection();
    if (!instance || !model || !selection) return;
    const edits: editor.IIdentifiedSingleEditOperation[] = [];
    for (let line = selection.startLineNumber; line <= selection.endLineNumber; line += 1) {
      const lineContent = model.getLineContent(line);
      if (lineContent.startsWith(prefix)) {
        edits.push({
          range: {
            startLineNumber: line,
            startColumn: 1,
            endLineNumber: line,
            endColumn: prefix.length + 1,
          },
          text: "",
        });
      } else {
        edits.push({
          range: {
            startLineNumber: line,
            startColumn: 1,
            endLineNumber: line,
            endColumn: 1,
          },
          text: prefix,
        });
      }
    }
    instance.executeEdits("toolbar", edits);
    instance.focus();
  };

  const insertImageMarkdown = (url: string, alt = 'image') => {
    if (!url.trim()) return;
    insertText(`![${alt.replace(/[[\]]/g, '')}](${url.trim()})\n`);
    setImagePanel(false);
    setImageUrl("");
  };

  async function insertImageFile(
    file: File,
    range: NonNullable<ReturnType<editor.IStandaloneCodeEditor["getSelection"]>>,
  ) {
    try {
      const result = await uploadImageFile(file);
      const editorInstance = editorRef.current;
      if (!editorInstance) return;
      editorInstance.executeEdits(undefined, [{
        range,
        text: buildMarkdownImage(file.name, result.url, {
          blurhash: result.blurhash,
          width: result.width,
          height: result.height,
        }),
      }]);
      setImagePanel(false);
    } catch (error) {
      console.error(error);
      showAlert(error instanceof Error ? error.message : t("upload.failed"));
    }
  }

  const handlePaste = async (event: React.ClipboardEvent<HTMLDivElement>) => {
    const clipboardData = event.clipboardData;
    if (clipboardData.files.length === 1) {
      const instance = editorRef.current;
      if (!instance) return;
      instance.trigger(undefined, "undo", undefined);
      setUploading(true);
      const myfile = clipboardData.files[0] as File;
      const selection = instance.getSelection();
      if (!selection) {
        setUploading(false);
        return;
      }
      void insertImageFile(myfile, selection).finally(() => {
        setUploading(false);
      });
    }
  };

  const handleEditorMount = (mounted: editor.IStandaloneCodeEditor) => {
    editorRef.current = mounted;

    mounted.onDidCompositionStart(() => {
      isComposingRef.current = true;
    });

    mounted.onDidCompositionEnd(() => {
      isComposingRef.current = false;
      setContent(mounted.getValue());
    });

    mounted.onDidChangeModelContent(() => {
      if (!isComposingRef.current) {
        setContent(mounted.getValue());
      }
    });

    mounted.onDidBlurEditorText(() => {
      setContent(mounted.getValue());
    });
  };

  useEffect(() => {
    const instance = editorRef.current;
    if (!instance) return;
    const model = instance.getModel();
    if (!model) return;
    if (model.getValue() !== content) {
      instance.setValue(content);
    }
  }, [content]);

  useEffect(() => {
    if (!fullscreen) return;
    const previous = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    return () => {
      document.body.style.overflow = previous;
    };
  }, [fullscreen]);

  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key !== "Escape") return;
      setImagePanel(false);
      setFullscreen(false);
    };
    window.addEventListener("keydown", onKeyDown);
    return () => {
      window.removeEventListener("keydown", onKeyDown);
    };
  }, []);

  return (
    <div className={`flex flex-col gap-0 sm:gap-3 ${fullscreen ? "fixed inset-0 z-[80] bg-w p-3 sm:p-4" : ""}`}>
      <div className="relative">
        <FlatInset className="flex flex-wrap items-center gap-1 border-0 border-b border-black/10 rounded-none bg-transparent p-3 dark:border-white/10 sm:gap-2">
          <FlatTabButton active={preview === 'edit'} onClick={() => setPreview('edit')}> {t("edit")} </FlatTabButton>
          <FlatTabButton active={preview === 'preview'} onClick={() => setPreview('preview')}> {t("preview")} </FlatTabButton>
          <FlatTabButton active={preview === 'comparison'} onClick={() => setPreview('comparison')}> {t("comparison")} </FlatTabButton>
          <div className="mx-1 hidden h-5 w-px bg-black/10 sm:block dark:bg-white/10" />
          <ToolButton icon="ri-heading" label={t("editor.heading")} onClick={() => prefixLines('## ')} />
          <ToolButton icon="ri-bold" label={t("editor.bold")} onClick={() => wrapSelection('**', '**', t("editor.bold"))} />
          <ToolButton icon="ri-italic" label={t("editor.italic")} onClick={() => wrapSelection('*', '*', t("editor.italic"))} />
          <ToolButton icon="ri-link" label={t("editor.link")} onClick={() => wrapSelection('[', '](https://)', t("editor.link"))} />
          <ToolButton icon="ri-image-line" label={t("editor.image")} active={imagePanel} onClick={() => setImagePanel((open) => !open)} />
          <ToolButton icon="ri-double-quotes-l" label={t("editor.quote")} onClick={() => prefixLines('> ')} />
          <ToolButton icon="ri-list-ordered" label={t("editor.ol")} onClick={() => prefixLines('1. ')} />
          <ToolButton icon="ri-list-unordered" label={t("editor.ul")} onClick={() => prefixLines('- ')} />
          <ToolButton icon="ri-code-s-slash-line" label={t("editor.code")} onClick={() => wrapSelection('`', '`', t("editor.code"))} />
          <ToolButton icon="ri-code-box-line" label={t("editor.codeblock")} onClick={() => wrapSelection('```\n', '\n```', t("editor.codeblock"))} />
          <ToolButton icon="ri-separator" label={t("editor.hr")} onClick={() => insertText('\n\n---\n\n')} />
          <div className="flex-grow" />
          {uploading &&
            <div className="flex flex-row items-center space-x-2">
              <Loading type="spin" color="#FC466B" height={16} width={16} />
              <span className="text-sm text-neutral-500">{t('uploading')}</span>
            </div>
          }
          <ToolButton
            icon={fullscreen ? "ri-fullscreen-exit-line" : "ri-fullscreen-line"}
            label={t("editor.fullscreen")}
            active={fullscreen}
            onClick={() => setFullscreen((value) => !value)}
          />
        </FlatInset>
        {imagePanel && (
          <div className="absolute left-3 right-3 top-full z-20 mt-2 rounded-2xl border border-black/10 bg-w p-3 shadow-lg dark:border-white/10">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
              <input
                type="text"
                value={imageUrl}
                placeholder={t("editor.image_url")}
                onChange={(event) => setImageUrl(event.target.value)}
                onKeyDown={(event) => {
                  if (event.key === 'Enter') {
                    event.preventDefault();
                    insertImageMarkdown(imageUrl);
                  }
                }}
                className="min-w-0 flex-1 rounded-xl border border-black/10 bg-w px-3 py-2 text-sm t-primary outline-none placeholder:text-neutral-400 focus:border-black/20 focus:ring-2 focus:ring-theme/10 dark:border-white/10 dark:placeholder:text-neutral-500"
              />
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  onClick={() => insertImageMarkdown(imageUrl)}
                  className="inline-flex items-center gap-2 rounded-xl border border-black/10 bg-w px-3 py-2 text-sm t-primary transition-colors hover:border-black/20 dark:border-white/10"
                >
                  {t("editor.insert")}
                </button>
                <button
                  type="button"
                  onClick={() => imageInputRef.current?.click()}
                  className="inline-flex items-center gap-2 rounded-xl border border-black/10 bg-w px-3 py-2 text-sm t-primary transition-colors hover:border-black/20 dark:border-white/10"
                >
                  <i className="ri-upload-2-line" aria-hidden="true" />
                  <span>{t("upload.title")}</span>
                </button>
              </div>
            </div>
            <input
              ref={imageInputRef}
              className="hidden"
              type="file"
              accept="image/gif,image/jpeg,image/jpg,image/png,image/webp"
              onChange={(event) => {
                const file = event.currentTarget.files?.[0];
                const instance = editorRef.current;
                const selection = instance?.getSelection();
                if (!file || !instance || !selection) return;
                if (file.size > 5 * 1024000) {
                  showAlert(t("upload.failed$size", { size: 5 }));
                  event.currentTarget.value = '';
                  return;
                }
                setUploading(true);
                void insertImageFile(file, selection).finally(() => {
                  setUploading(false);
                  event.currentTarget.value = '';
                });
              }}
            />
          </div>
        )}
      </div>
      <div className={`grid grid-cols-1 gap-0 sm:gap-4 ${preview === 'comparison' ? "lg:grid-cols-2" : ""} ${fullscreen ? "min-h-0 flex-1" : ""}`}>
        <div className={"flex min-w-0 flex-col " + (preview === 'preview' ? "hidden" : "")}>
          <div
            className={"relative min-h-0 overflow-hidden rounded-none border-0 bg-w"}
            onDragOver={(e) => {
              e.preventDefault();
            }}
            onDrop={(e) => {
              e.preventDefault();
              const instance = editorRef.current;
              if (!instance) return;
              for (let i = 0; i < e.dataTransfer.files.length; i++) {
                const selection = instance.getSelection();
                if (!selection) return;
                const file = e.dataTransfer.files[i];
                setUploading(true);
                void insertImageFile(file, selection).finally(() => {
                  setUploading(false);
                });
              }
            }}
            onPaste={handlePaste}
          >
            <Editor
              onMount={handleEditorMount}
              height={editorHeight}
              defaultLanguage="markdown"
              defaultValue={content}
              theme={colorMode === "dark" ? "vs-dark" : "light"}
              options={{
                wordWrap: "on",
                fontFamily: "Sarasa Mono SC, JetBrains Mono, monospace",
                fontLigatures: false,
                letterSpacing: 0,
                fontSize: 14,
                lineNumbers: "off",
                accessibilitySupport: "off",
                unicodeHighlight: { ambiguousCharacters: false },
                renderWhitespace: "none",
                renderControlCharacters: false,
                smoothScrolling: false,
                dragAndDrop: true,
                pasteAs: { enabled: false },
              }}
            />
          </div>
        </div>
        <div
          className={"min-h-0 overflow-y-auto rounded-none border-0 bg-w px-4 py-4 border-t sm:border-none " + (preview === 'edit' ? "hidden" : "")}
          style={{ height: editorHeight }}
        >
          <Markdown content={content ? content : placeholder} />
        </div>
      </div>
      <AlertUI />
    </div>
  );
}
