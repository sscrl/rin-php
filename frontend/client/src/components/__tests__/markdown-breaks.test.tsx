import { render } from "@testing-library/react";
import ReactMarkdown from "react-markdown";
import remarkBreaks from "remark-breaks";
import gfm from "remark-gfm";
import { describe, expect, it } from "vitest";
import "../../test/setup";

describe("markdown hard line breaks", () => {
  it("renders a single newline as a visual line break", () => {
    const { container } = render(
      <ReactMarkdown remarkPlugins={[gfm, remarkBreaks]}>
        {"第一行\n第二行"}
      </ReactMarkdown>
    );

    expect(container.querySelector("br")).not.toBeNull();
    expect(container.textContent).toContain("第一行");
    expect(container.textContent).toContain("第二行");
  });

  it("keeps two paragraphs when a blank line is present", () => {
    const { container } = render(
      <ReactMarkdown remarkPlugins={[gfm, remarkBreaks]}>
        {"第一段\n\n第二段"}
      </ReactMarkdown>
    );

    expect(container.querySelectorAll("p")).toHaveLength(2);
  });
});
