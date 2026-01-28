(function () {
  "use strict";

  const state = window.MediaLibraryLite || {};
  const ajaxUrl = String(state.ajaxUrl || "");
  const securityToken = String(state.token || "");
  const currentType = String(state.type || "image");

  const queryOne = (selector, root) => (root || document).querySelector(selector);
  const queryAll = (selector, root) => Array.from((root || document).querySelectorAll(selector));

  function openModal(name) {
    const modalElement = document.querySelector(`[data-ml-modal="${name}"]`);
    if (!modalElement) return;
    modalElement.classList.add("is-open");
    modalElement.setAttribute("aria-hidden", "false");
  }

  function closeAllModals() {
    queryAll("[data-ml-modal]").forEach((modalElement) => {
      modalElement.classList.remove("is-open");
      modalElement.setAttribute("aria-hidden", "true");
    });
  }

  function toast(message) {
    if (!message) return;
    window.alert(message);
  }

  async function postJson(params, formData) {
    const urlObject = new URL(ajaxUrl, window.location.href);
    Object.entries(params || {}).forEach(([key, value]) => urlObject.searchParams.set(key, String(value)));

    const bodyData = formData || new FormData();
    bodyData.append("_", securityToken);

    const response = await fetch(urlObject.toString(), {
      method: "POST",
      body: bodyData,
      headers: {
        "X-Requested-With": "XMLHttpRequest",
      },
      credentials: "same-origin",
    });

    const text = await response.text();
    if (!response.ok) {
      console.error("[MediaLibraryLite] HTTP error:", response.status, urlObject.toString(), text);
    }
    try {
      return JSON.parse(text);
    } catch (parseError) {
      console.error("[MediaLibraryLite] JSON parse failed:", urlObject.toString(), text);
      throw new Error(`响应解析失败（HTTP ${response.status}）`);
    }
  }

  function findItemRoot(element) {
    return element ? element.closest("[data-cid]") : null;
  }

  function getItemPayload(rootElement) {
    return {
      cid: rootElement ? rootElement.getAttribute("data-cid") : "",
      url: rootElement ? rootElement.getAttribute("data-url") : "",
      name: rootElement ? rootElement.getAttribute("data-name") : "",
    };
  }

  async function copyToClipboard(text) {
    if (!text) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      await navigator.clipboard.writeText(text);
      return;
    }
    const input = document.createElement("textarea");
    input.value = text;
    document.body.appendChild(input);
    input.select();
    document.execCommand("copy");
    document.body.removeChild(input);
  }

  async function handleDelete(cid, rootElement) {
    if (!cid) {
      toast("无法获取文件 ID");
      return;
    }
    if (!window.confirm("确定要删除该文件吗？")) return;

    try {
      const formData = new FormData();
      formData.append("cid", cid);
      const data = await postJson({ action: "delete" }, formData);

      if (!data || !data.success) {
        console.error("[MediaLibraryLite] delete failed:", data);
        toast((data && data.message) || "删除失败");
        return;
      }

      if (rootElement) {
        rootElement.remove();
      } else {
        window.location.reload();
      }
    } catch (error) {
      console.error("[MediaLibraryLite] delete error:", error);
      toast(error.message || "删除失败");
    }
  }

  async function handleUpload(files) {
    if (!files || files.length === 0) return;

    const statusElement = queryOne("[data-ml-upload-status]");
    if (statusElement) {
      statusElement.hidden = false;
      statusElement.textContent = "正在上传...";
    }

    const formData = new FormData();
    Array.from(files).forEach((file) => formData.append("file[]", file));
    formData.append("category", currentType);

    try {
      const data = await postJson({ action: "upload" }, formData);
      if (!data || !data.success) {
        console.error("[MediaLibraryLite] upload failed:", data);
        if (statusElement) {
          statusElement.textContent = (data && data.message) || "上传失败";
        }
        return;
      }
      if (data.warnings && data.warnings.length) {
        toast(data.warnings[0]);
      }
      window.location.reload();
    } catch (error) {
      console.error("[MediaLibraryLite] upload error:", error);
      if (statusElement) {
        statusElement.textContent = error.message || "上传失败";
      }
    }
  }

  function handlePreview(url, name) {
    if (!url) {
      toast("无法预览该文件");
      return;
    }
    const modalElement = document.querySelector('[data-ml-modal="preview"]');
    if (!modalElement) return;

    const imageElement = queryOne("[data-ml-preview-img]", modalElement);
    const metaElement = queryOne("[data-ml-preview-meta]", modalElement);

    if (imageElement) {
      imageElement.src = url;
      imageElement.alt = name || "";
    }
    if (metaElement) {
      metaElement.textContent = url || "";
    }

    openModal("preview");
  }

  function initUploadModal() {
    const modalElement = document.querySelector('[data-ml-modal="upload"]');
    if (!modalElement) return;

    const dropzone = queryOne(".ml-dropzone", modalElement);
    const inputElement = queryOne('[data-ml-input="file"]', modalElement);

    if (!dropzone || !inputElement) return;

    dropzone.addEventListener("dragover", (event) => {
      event.preventDefault();
      dropzone.classList.add("is-dragover");
    });

    dropzone.addEventListener("dragleave", () => {
      dropzone.classList.remove("is-dragover");
    });

    dropzone.addEventListener("drop", (event) => {
      event.preventDefault();
      dropzone.classList.remove("is-dragover");
      if (event.dataTransfer && event.dataTransfer.files) {
        handleUpload(event.dataTransfer.files);
      }
    });

    inputElement.addEventListener("change", () => {
      handleUpload(inputElement.files);
      inputElement.value = "";
    });
  }

  document.addEventListener("click", async (event) => {
    const targetElement = event.target;
    if (!(targetElement instanceof Element)) return;

    const actionElement = targetElement.closest("[data-ml-action]");
    const action = actionElement ? actionElement.getAttribute("data-ml-action") : null;

    if (!action) return;

    if (action === "open-upload") {
      openModal("upload");
      return;
    }

    if (action === "close-modal") {
      closeAllModals();
      return;
    }

    const rootElement = findItemRoot(actionElement);
    const payload = getItemPayload(rootElement);

    switch (action) {
      case "copy":
        try {
          if (!payload.url) {
            toast("没有可复制的链接");
            return;
          }
          await copyToClipboard(payload.url);
          toast("已复制链接");
        } catch (error) {
          toast("复制失败");
        }
        break;
      case "open":
        if (!payload.url) {
          toast("无法打开该文件");
          break;
        }
        window.open(payload.url, "_blank", "noopener");
        break;
      case "delete":
        await handleDelete(payload.cid, rootElement);
        break;
      case "preview":
        handlePreview(payload.url, payload.name);
        break;
      default:
        break;
    }
  });

  initUploadModal();
})();
