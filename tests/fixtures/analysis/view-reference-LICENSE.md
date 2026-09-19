The MIT License (MIT)

Copyright (c) Taylor Otwell

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.

The view-reference fixtures contain native Laravel framework v13.31.0 declarations
and AST-printed method excerpts (commit 7c75fbf93f91fa077d3df1c820cc14f4e59a9774).
They are parsed as analyzer fixtures and never executed.

The view-first Factory, Arr and helpers fixtures also retain selected Laravel framework methods from commit 7c75fbf under this license. Class names in method bodies are resolved for standalone analysis.

The conditional-view-Factory.php.stub fixture retains native renderWhen, renderUnless, renderEach and parseData methods from the same Laravel Factory source.
