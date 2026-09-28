import { useCallback, useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'

export interface TableOfContent {
    index: number
    text: string
    marginLeft: number
    element: HTMLElement
}

const getHeaderScrollOffset = () => {
    const rawValue = getComputedStyle(document.documentElement)
        .getPropertyValue('--header-scroll-offset')
        .trim()
    const offset = Number.parseFloat(rawValue)
    return Number.isFinite(offset) ? offset : 0
}

const headingSelector = 'h1, h2, h3, h4, h5, h6'

const collectHeadings = (selector: string) => {
    const roots = Array.from(document.querySelectorAll<HTMLElement>(selector))
    const seen = new Set<HTMLElement>()
    const headers: HTMLElement[] = []
    for (const root of roots) {
        if (root.closest('[data-toc-panel="true"]')) {
            continue
        }
        const nodes = [
            ...(root.matches(headingSelector) ? [root] : []),
            ...Array.from(root.querySelectorAll<HTMLElement>(headingSelector)),
        ]
        for (const header of nodes) {
            if (seen.has(header) || header.closest('[data-toc-panel="true"]')) {
                continue
            }
            seen.add(header)
            headers.push(header)
        }
    }
    return headers
}

const headingSignature = (headers: HTMLElement[]) =>
    headers.map((header) => `${header.tagName}:${header.textContent ?? ''}`).join('\n')

const useTableOfContents = (selector: string) => {
    const intersectingListRef = useRef<boolean[]>([])
    const [tableOfContents, setTableOfContents] = useState<TableOfContent[]>([])
    const [activeIndex, setActiveIndex] = useState(0)
    const { t } = useTranslation()
    const io = useRef<IntersectionObserver | null>(null)
    const [scanKey, setScanKey] = useState(0)
    const lastSignature = useRef('')

    useEffect(() => {
        let stopped = false
        const intersectingList = intersectingListRef.current

        const scan = () => {
            if (stopped) {
                return
            }
            const headers = collectHeadings(selector)
            const signature = headingSignature(headers)
            if (signature === lastSignature.current) {
                return
            }
            lastSignature.current = signature

            const tocData = headers.map<TableOfContent>((header, i) => ({
                index: i,
                text: (header.textContent || '').trim(),
                marginLeft: (Number(header.tagName.charAt(1)) - 1) * 10,
                element: header,
            }))
            setTableOfContents(tocData)
            setActiveIndex(0)

            if (io.current) {
                io.current.disconnect()
            }
            io.current = new IntersectionObserver(
                (entries) => {
                    entries.forEach(({ target, isIntersecting }) => {
                        const idx = Number((target as HTMLElement).dataset.id || 0)
                        intersectingList[idx] = isIntersecting
                    })
                    const currentIndex = intersectingList.findIndex((item) => item)
                    let nextIndex = currentIndex - 1
                    if (currentIndex === -1) {
                        nextIndex = intersectingList.length - 1
                    } else if (currentIndex === 0) {
                        nextIndex = 0
                    }
                    setActiveIndex(Math.max(nextIndex, 0))
                },
                { rootMargin: '-20% 0px 10000px 0px', threshold: 0 }
            )
            intersectingList.length = 0
            headers.forEach((header, i) => {
                header.setAttribute('data-id', i.toString())
                intersectingList.push(false)
                io.current!.observe(header)
            })
        }

        scan()
        const observer = new MutationObserver(scan)
        observer.observe(document.body, { childList: true, subtree: true, characterData: true })
        const timers = [0, 32, 80, 160, 320, 640, 1200].map((ms) => window.setTimeout(scan, ms))

        return () => {
            stopped = true
            observer.disconnect()
            timers.forEach((timer) => window.clearTimeout(timer))
            if (io.current) {
                io.current.disconnect()
            }
        }
    }, [scanKey, selector])

    const cleanup = useCallback((_newId: string) => {
        lastSignature.current = ''
        setScanKey((value) => value + 1)
        if (io.current) {
            io.current.disconnect()
        }
    }, [])

    return {
        TOC: () => (
            <div data-toc-panel="true" className="rounded-2xl bg-w py-4 px-4 t-primary">
                <h2 className="text-lg font-bold">{t("index.title")}</h2>
                <ul className="max-h-[calc(100vh-10.25rem)] overflow-auto" style={{ scrollbarWidth: "none" }}>
                    {tableOfContents.length === 0 && <li>{t("index.empty.title")}</li>}
                    {tableOfContents.map((item) => (
                        <li
                            key={`toc$${item.index}`}
                            className={`cursor-pointer hover:opacity-50 ${activeIndex === item.index ? "text-theme" : ""}`}
                            style={{ marginLeft: item.marginLeft }}
                            onClick={() => {
                                const top = item.element.getBoundingClientRect().top + window.scrollY - getHeaderScrollOffset()
                                window.scrollTo({
                                    top: Math.max(top, 0),
                                    behavior: "smooth",
                                })
                            }}
                        >
                            {item.text}
                        </li>
                    ))}
                </ul>
            </div>
        ),
        cleanup,
    }
}

export default useTableOfContents
